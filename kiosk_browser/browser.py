import sys
import os
from PyQt5.QtCore import QUrl, Qt
from PyQt5.QtWidgets import QApplication, QMainWindow
from PyQt5.QtWebEngineWidgets import (
    QWebEngineView, 
    QWebEngineSettings, 
    QWebEnginePage, 
    QWebEngineScript
)

# PyInstaller paketlemesinde WebEngine yollarını ayarla
if hasattr(sys, '_MEIPASS'):
    base_dir = sys._MEIPASS
    possible_paths = [
        os.path.join(base_dir, 'PyQt5', 'Qt5', 'bin', 'QtWebEngineProcess.exe'),
        os.path.join(base_dir, 'PyQt5', 'Qt', 'bin', 'QtWebEngineProcess.exe'),
        os.path.join(base_dir, 'QtWebEngineProcess.exe'),
        os.path.join(base_dir, 'PyQt5', 'Qt5', 'lib', 'QtWebEngineCore.framework', 'Helpers', 'QtWebEngineProcess.app', 'Contents', 'MacOS', 'QtWebEngineProcess')
    ]
    for p in possible_paths:
        if os.path.exists(p):
            os.environ['QTWEBENGINEPROCESS_PATH'] = p
            break

# Otomatik İzin Yönetimi (Konum / Geolocation izinlerini otomatik onaylar)
class KioskWebEnginePage(QWebEnginePage):
    def __init__(self, parent=None):
        super().__init__(parent)
        self.featurePermissionRequested.connect(self.handle_feature_permission)

    def handle_feature_permission(self, security_origin, feature):
        # Geolocation (Konum) ve tüm web izinlerini onay ver
        self.setFeaturePermission(
            security_origin, 
            feature, 
            QWebEnginePage.PermissionGrantedByUser
        )

# Donanımsal GPS olmayan Windows/Mac cihazlar için akıllı konum çözücü
GEO_POLYFILL_JS = """
(function() {
    if (!navigator.geolocation) {
        navigator.geolocation = {};
    }
    const origGetCurrentPosition = navigator.geolocation.getCurrentPosition;
    const DEFAULT_LAT = 40.6520;
    const DEFAULT_LNG = 29.3520;

    function buildPosition(lat, lng) {
        return {
            coords: {
                latitude: lat,
                longitude: lng,
                altitude: null,
                accuracy: 25,
                altitudeAccuracy: null,
                heading: null,
                speed: null
            },
            timestamp: Date.now()
        };
    }

    navigator.geolocation.getCurrentPosition = function(successCallback, errorCallback, options) {
        let isDone = false;

        function fallback() {
            if (isDone) return;
            isDone = true;
            // 1. IP tabanlı konum servisi
            fetch('https://ipapi.co/json/')
                .then(r => r.json())
                .then(data => {
                    if (data && data.latitude && data.longitude) {
                        successCallback(buildPosition(parseFloat(data.latitude), parseFloat(data.longitude)));
                    } else {
                        successCallback(buildPosition(DEFAULT_LAT, DEFAULT_LNG));
                    }
                })
                .catch(() => {
                    // 2. Çiftlikköy Belediyesi Mezarlık koordinatları
                    successCallback(buildPosition(DEFAULT_LAT, DEFAULT_LNG));
                });
        }

        try {
            if (typeof origGetCurrentPosition === 'function') {
                origGetCurrentPosition.call(
                    navigator.geolocation,
                    function(pos) {
                        if (!isDone) {
                            isDone = true;
                            successCallback(pos);
                        }
                    },
                    function(err) {
                        console.warn('Native GPS basarisiz, kiosk konum yedegi kullaniliyor:', err);
                        fallback();
                    },
                    { enableHighAccuracy: true, timeout: 4000, maximumAge: 60000 }
                );
                // 4.5 saniye zaman aşımı emniyeti
                setTimeout(fallback, 4500);
            } else {
                fallback();
            }
        } catch(e) {
            fallback();
        }
    };
})();
"""

class KioskBrowser(QMainWindow):
    def __init__(self):
        super().__init__()
        self.setWindowTitle("Çiftlikköy MBS Kiosk")
        
        # Tam ekran ve çerçevesiz pencere (Kiosk Modu)
        self.setWindowFlags(Qt.FramelessWindowHint | Qt.WindowStaysOnTopHint)
        self.showFullScreen()
        
        # Web motorunu ve özel sayfayı oluştur
        self.browser = QWebEngineView()
        self.page = KioskWebEnginePage(self.browser)
        self.browser.setPage(self.page)
        
        # Geolocation betiğini enjekte et
        script = QWebEngineScript()
        script.setName("KioskGeolocationFallback")
        script.setInjectionPoint(QWebEngineScript.DocumentCreation)
        script.setWorldId(QWebEngineScript.MainWorld)
        script.setRunsOnSubFrames(True)
        script.setSourceCode(GEO_POLYFILL_JS)
        self.browser.page().profile().scripts().insert(script)

        # Sayfa yüklendiğinde de garanti olarak çalıştır
        self.browser.loadFinished.connect(lambda ok: self.browser.page().runJavaScript(GEO_POLYFILL_JS))
        
        # Güvenlik: Sağ tık bağlam menüsünü engelle
        self.browser.setContextMenuPolicy(Qt.NoContextMenu)
        
        # Web görünüm ayarları
        settings = self.browser.settings()
        settings.setAttribute(QWebEngineSettings.JavascriptEnabled, True)
        settings.setAttribute(QWebEngineSettings.LocalStorageEnabled, True)
        settings.setAttribute(QWebEngineSettings.ScrollAnimatorEnabled, True)
        settings.setAttribute(QWebEngineSettings.AllowGeolocationOnInsecureOrigins, True)
        
        # Kiosk başlangıç adresi
        self.browser.setUrl(QUrl("https://ciftlikkoymbs.com/kiosk")) 
        
        self.setCentralWidget(self.browser)

    # Sağ tık menüsü çağrısını engelle
    def contextMenuEvent(self, event):
        event.ignore()

    # Klavye kontrolleri ve güvenlik (Windows & macOS uyumlu)
    def keyPressEvent(self, event):
        modifiers = event.modifiers()
        key = event.key()

        # Gizli yönetici çıkış kombinasyonu:
        # Windows: Ctrl + Shift + Q
        # macOS: Cmd + Shift + Q veya Ctrl + Shift + Q
        is_admin_exit = (key == Qt.Key_Q) and (
            (modifiers == (Qt.ControlModifier | Qt.ShiftModifier)) or
            (modifiers == (Qt.MetaModifier | Qt.ShiftModifier))
        )
        if is_admin_exit:
            QApplication.quit()
            return
            
        # macOS üzerinde Cmd tuşu kısayollarını (Cmd+Q, Cmd+W, Cmd+M, Cmd+H) engelle
        if bool(modifiers & Qt.MetaModifier) and key in (Qt.Key_Q, Qt.Key_W, Qt.Key_M, Qt.Key_H):
            event.ignore()
            return

        # Escape tuşunu yoksay
        if key == Qt.Key_Escape:
            event.ignore()
        # Alt+F4 kombinasyonunu yoksay
        elif key == Qt.Key_F4 and (modifiers & Qt.AltModifier):
            event.ignore()
        # F11 (tam ekran), F12 (incele), F5 (yenile) engelleme
        elif key in (Qt.Key_F11, Qt.Key_F12):
            event.ignore()
        else:
            super().keyPressEvent(event)

if __name__ == '__main__':
    # Yüksek DPI ekranlar için ölçeklendirme desteği
    QApplication.setAttribute(Qt.AA_EnableHighDpiScaling, True)
    QApplication.setAttribute(Qt.AA_UseHighDpiPixmaps, True)
    
    app = QApplication(sys.argv)
    window = KioskBrowser()
    sys.exit(app.exec_())
