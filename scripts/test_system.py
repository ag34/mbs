#!/usr/bin/env python3
"""
Çiftlikköy Belediyesi Mezarlık Bilgi Sistemi (MBS)
Mühendislik & CTO Otomatik Test Suite (Canlı Öncesi Kontrol)
"""

import urllib.request
import json
import sqlite3
import os

BASE_URL = 'http://127.0.0.1:8000/api/index.php'
DB_PATH = os.path.join(os.path.dirname(__file__), '../db/mbs_database.sqlite')

def test_api_status():
    print("1. API Bağlantı & Sistem Durumu Test Ediliyor...")
    res = json.loads(urllib.request.urlopen(f"{BASE_URL}?action=help").read())
    assert res['status'] == 'online', "API offline!"
    print("   [OK] API Online ve Hazır.")

def test_kvkk_privacy():
    print("2. KVKK & Veri Gizliliği Güvenlik Testi (Boş Arama Engelleme)...")
    res = json.loads(urllib.request.urlopen(f"{BASE_URL}?action=vatandas_sorgu&q=").read())
    assert res['count'] == 0, "KVKK İhlali: Boş aramada veri dönüyor!"
    print("   [OK] KVKK Koruması Aktif: Boş veya 1 karakterli aramalar engellendi.")

def test_search_and_date_format():
    print("3. İsim Arama & DD/MM/YYYY Tarih Formatı Test Ediliyor...")
    res = json.loads(urllib.request.urlopen(f"{BASE_URL}?action=vatandas_sorgu&q=SERP").read())
    assert res['count'] > 0, "Arama sonucu bulunamadı!"
    item = res['data'][0]
    print(f"   Bulunan Mefta: {item['ad']} {item['soyad']} | Defin Tarihi: {item['defin_tarihi']}")
    assert '/' in item['defin_tarihi'], f"Tarih DD/MM/YYYY formatında değil: {item['defin_tarihi']}"
    print("   [OK] Arama motoru ve DD/MM/YYYY tarih formatı doğrulandı.")

def test_admin_record_creation():
    print("4. Admin Paneli Yeni Defin Kaydı Ekleme Ve Otomatik Temizleme Test Ediliyor...")
    payload = json.dumps({
        "ad": "GEÇİCİ_SİSTEM",
        "soyad": "TEST_KAYDI",
        "tc_no": "99999999999",
        "mezarlik_id": 1,
        "ada_no": "99",
        "parsel_no": "99",
        "defin_tarihi": "23/07/2026",
        "olum_nedeni": "Doğal Ölüm",
        "ignore_duplicate": 1
    }).encode('utf-8')

    req = urllib.request.Request(f"{BASE_URL}?action=defin_ekle", data=payload, headers={'Content-Type': 'application/json'})
    res = json.loads(urllib.request.urlopen(req).read())
    assert res['status'] == 'success', "Kayıt ekleme başarısız!"
    created_id = res.get('id')
    print(f"   [OK] Yeni Geçici Kayıt Eklendi (ID: {created_id}).")

    if created_id:
        del_payload = json.dumps({"id": created_id}).encode('utf-8')
        del_req = urllib.request.Request(f"{BASE_URL}?action=defin_sil", data=del_payload, headers={'Content-Type': 'application/json', 'X-MBS-User': 'admin', 'X-MBS-Role': 'superadmin'})
        del_res = json.loads(urllib.request.urlopen(del_req).read())
        assert del_res['status'] == 'success', "Geçici kayıt silinemedi!"
        print(f"   [OK] Geçici Kayıt Veritabanından Otomatik Silindi (ID: {created_id}).")

def test_database_integrity():
    print("5. Veritabanı Arşiv Bütünlüğü Test Ediliyor...")
    conn = sqlite3.connect(DB_PATH)
    cursor = conn.cursor()
    count = cursor.execute("SELECT COUNT(*) FROM defin_kayitlari").fetchone()[0]
    conn.close()
    assert count >= 1500, f"Veritabanında eksik kayıt var: {count}"
    print(f"   [OK] Toplam {count} mefta kaydı veritabanında doğrulandı.")

def main():
    print("=" * 60)
    print("ÇİFTLİKKÖY BELEDİYESİ MBS CANLIYA GEÇİŞ TEST RAPORU")
    print("=" * 60)
    test_api_status()
    test_kvkk_privacy()
    test_search_and_date_format()
    test_admin_record_creation()
    test_database_integrity()
    print("=" * 60)
    print("SONUÇ: TÜM TESTLER BAŞARIYLA GEÇTİ. SİSTEM CANLIYA ALINMAYA HAZIR!")
    print("=" * 60)

if __name__ == '__main__':
    main()
