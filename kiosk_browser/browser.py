import sys
from PyQt5.QtCore import QUrl, Qt
from PyQt5.QtWidgets import QApplication, QMainWindow
from PyQt5.QtWebEngineWidgets import QWebEngineView

class KioskBrowser(QMainWindow):
    def __init__(self):
        super().__init__()
        self.setWindowTitle("Kiosk")
        
        # Tam ekran ve çerçevesiz pencere (Kiosk Modu)
        self.setWindowFlags(Qt.FramelessWindowHint | Qt.WindowStaysOnTopHint)
        self.showFullScreen()
        
        # Web motorunu oluştur (Edge/WebView2'den bağımsız Chromium tabanlı)
        self.browser = QWebEngineView()
        
        # Başlangıç adresini belirleyin (Buraya açılmasını istediğiniz adresi girin)
        self.browser.setUrl(QUrl("https://www.google.com")) 
        
        self.setCentralWidget(self.browser)

    # Klavye kısayollarını devre dışı bırak
    def keyPressEvent(self, event):
        # Escape tuşunu yoksay
        if event.key() == Qt.Key_Escape:
            event.ignore()
        # Alt+F4 kombinasyonunu yoksay
        elif event.key() == Qt.Key_F4 and (event.modifiers() & Qt.AltModifier):
            event.ignore()
        else:
            super().keyPressEvent(event)

if __name__ == '__main__':
    app = QApplication(sys.argv)
    window = KioskBrowser()
    sys.exit(app.exec_())
