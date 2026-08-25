#!/usr/bin/env python3
"""
MySQL phpMyAdmin için Toplu Veri Dump Hazırlayıcı
"""

import sqlite3
import os

DB_PATH = os.path.join(os.path.dirname(__file__), '../db/mbs_database.sqlite')
DUMP_PATH = os.path.join(os.path.dirname(__file__), '../db/archive_mysql_dump.sql')

def main():
    conn = sqlite3.connect(DB_PATH)
    cursor = conn.cursor()

    rows = cursor.execute("SELECT tc_no, ad, soyad, baba_adi, anne_adi, dogum_tarihi, olum_tarihi, defin_tarihi, olum_nedeni, mezarlik_id, ada_no, parsel_no, lat, lng, yakin_ad_soyad, yakin_telefon, aciklama FROM defin_kayitlari").fetchall()
    conn.close()

    lines = ["-- ====================================================", "-- TOPLU DEFİN KAYITLARI MYSQL İÇE AKTARIM DUMP", "-- ====================================================", ""]

    def escape_sql(val):
        if val is None:
            return "NULL"
        val_str = str(val).replace("'", "''").replace("\\", "\\\\")
        return f"'{val_str}'"

    for r in rows:
        tc = escape_sql(r[0])
        ad = escape_sql(r[1])
        soyad = escape_sql(r[2])
        baba = escape_sql(r[3])
        anne = escape_sql(r[4])
        dogum = escape_sql(r[5])
        olum = escape_sql(r[6])
        defin = escape_sql(r[7]) or "'2024-01-01'"
        neden = escape_sql(r[8])
        mezarlik_id = r[9] or 1
        ada = escape_sql(r[10])
        parsel = escape_sql(r[11])
        lat = r[12] or "NULL"
        lng = r[13] or "NULL"
        yakin_ad = escape_sql(r[14])
        yakin_tel = escape_sql(r[15])
        aciklama = escape_sql(r[16])

        sql = f"INSERT INTO `defin_kayitlari` (`tc_no`, `ad`, `soyad`, `baba_adi`, `anne_adi`, `dogum_tarihi`, `olum_tarihi`, `defin_tarihi`, `olum_nedeni`, `mezarlik_id`, `ada_no_text`, `parsel_no_text`, `lat`, `lng`, `yakin_ad_soyad`, `yakin_telefon`, `aciklama`) VALUES ({tc}, {ad}, {soyad}, {baba}, {anne}, {dogum}, {olum}, {defin}, {neden}, {mezarlik_id}, {ada}, {parsel}, {lat}, {lng}, {yakin_ad}, {yakin_tel}, {aciklama});"
        lines.append(sql)

    with open(DUMP_PATH, 'w', encoding='utf-8') as f:
        f.write('\n'.join(lines))

    print(f"MySQL phpMyAdmin Dump Oluşturuldu: {len(rows)} kayıt {DUMP_PATH} dosyasına yazıldı.")

if __name__ == '__main__':
    main()
