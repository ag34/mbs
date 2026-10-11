import sys
import os
from PyQt5.QtCore import QUrl, Qt
from PyQt5.QtWidgets import QApplication, QMainWindow
from PyQt5.QtWebEngineWidgets import QWebEngineView, QWebEngineSettings

# PyInstaller tek dosya (onefile) modunda QtWebEngine dosyalarını bulabilmesi için
if hasattr(sys, '_MEIPASS'):
    base_dir = sys._MEIPASS
    possible_paths = [
        os.path.join(base_dir, 'PyQt5', 'Qt5', 'bin', 'QtWebEngineProcess.exe'),
        os.path.join(base_dir, 'PyQt5', 'Qt', 'bin', 'QtWebEngineProcess.exe'),
        os.path.join(base_dir, 'QtWebEngineProcess.exe')
    ]
    for p in possible_paths:
        if os.path.exists(p):
            os.environ['QTWEBENGINEPROCESS_PATH'] = p
            break

class KioskBrowser(QMainWindow):
    def __init__(self):
        super().__init__()
        self.setWindowTitle("Çiftlikköy MBS Kiosk")
        
        # Tam ekran ve çerçevesiz pencere (Kiosk Modu)
        self.setWindowFlags(Qt.FramelessWindowHint | Qt.WindowStaysOnTopHint)
        self.showFullScreen()
        
        # Web motorunu oluştur (Chromium tabanlı)
        self.browser = QWebEngineView()
        
        # Güvenlik: Sağ tık bağlam menüsünü engelle
        self.browser.setContextMenuPolicy(Qt.NoContextMenu)
        
        # Web görünüm ayarları
        settings = self.browser.settings()
        settings.setAttribute(QWebEngineSettings.JavascriptEnabled, True)
        settings.setAttribute(QWebEngineSettings.LocalStorageEnabled, True)
        settings.setAttribute(QWebEngineSettings.ScrollAnimatorEnabled, True)
        
        # Kiosk başlangıç adresi
        self.browser.setUrl(QUrl("https://ciftlikkoymbs.com/kiosk")) 
        
        self.setCentralWidget(self.browser)

    # Sağ tık menüsü çağrısını engelle
    def contextMenuEvent(self, event):
        event.ignore()

    # Klavye kontrolleri ve güvenlik
    def keyPressEvent(self, event):
        # Gizli yönetici çıkış kombinasyonu: Ctrl + Shift + Q
        if event.key() == Qt.Key_Q and (event.modifiers() == (Qt.ControlModifier | Qt.ShiftModifier)):
            QApplication.quit()
            return
            
        # Escape tuşunu yoksay
        if event.key() == Qt.Key_Escape:
            event.ignore()
        # Alt+F4 kombinasyonunu yoksay
        elif event.key() == Qt.Key_F4 and (event.modifiers() & Qt.AltModifier):
            event.ignore()
        # F11 (tam ekran), F12 (incele), F5 (yenile) engelleme
        elif event.key() in (Qt.Key_F11, Qt.Key_F12):
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
