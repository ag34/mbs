#!/bin/bash
set -e

# Script dizinine geç
DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" >/dev/null 2>&1 && pwd )"
cd "$DIR"

echo "=== MBS Kiosk Tarayıcı macOS Derleme Başlatılıyor ==="

# Python sanal ortamı kur
if [ ! -d "venv" ]; then
    echo "Python sanal ortamı (venv) oluşturuluyor..."
    python3 -m venv venv
fi

source venv/bin/activate

echo "Gerekli kütüphaneler yükleniyor..."
pip install --upgrade pip
pip install -r requirements.txt
pip install pyinstaller pyinstaller-hooks-contrib

echo "PyInstaller ile KioskBrowser.app paketi oluşturuluyor..."
pyinstaller --noconfirm --windowed --name "KioskBrowser" \
  --collect-all PyQt5 \
  --collect-all PyQtWebEngine \
  browser.py

echo "macOS DMG ve ZIP kalıpları oluşturuluyor..."
rm -f dist/KioskBrowser-macOS.dmg dist/KioskBrowser-macOS.zip

# DMG kalıbı oluştur
hdiutil create -volname "CiftlikkoyMBSKiosk" -srcfolder dist/KioskBrowser.app -ov -format UDZO dist/KioskBrowser-macOS.dmg

# ZIP arşivi oluştur
ditto -c -k --sequesterRsrc --keepParent dist/KioskBrowser.app dist/KioskBrowser-macOS.zip

echo "=== DERLEME BAŞARIYLA TAMAMLANDI! ==="
echo "Oluşturulan dosyalar:"
echo "1. dist/KioskBrowser.app      (Doğrudan çalıştırılabilir Mac uygulaması)"
echo "2. dist/KioskBrowser-macOS.dmg (Kurulum kalıbı)"
echo "3. dist/KioskBrowser-macOS.zip (Taşınabilir sıkıştırılmış arşiv)"
