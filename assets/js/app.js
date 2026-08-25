/**
 * Çiftlikköy Belediyesi Mezarlık Bilgi Sistemi (MBS)
 * Vatandaş Portalı & CBS Harita Motoru (Kök Dizin Uyumlu API Çağrısı)
 */

document.addEventListener('DOMContentLoaded', () => {
    initMap();
    initSearchEvents();
    loadInitialStats();
    checkUrlQrId();
});

let map = null;
let markersLayer = null;

/**
 * Kök Dizine Taşınmış Yapıya Göre API Yolu Çözümleyici
 */
function getApiPath(action, q = '', mezarlikId = 0) {
    let base = 'api/index.php';
    // Alt klasörlerde (admin veya kiosk) çalışırsa bir üst dizine çık
    if (window.location.pathname.includes('/admin/') || window.location.pathname.includes('/kiosk/')) {
        base = '../api/index.php';
    }
    return `${base}?action=${action}&q=${encodeURIComponent(q)}&mezarlik_id=${mezarlikId}`;
}

function formatDate(dateStr) {
    if (!dateStr) return '-';
    if (dateStr.includes('/')) return dateStr;
    const parts = dateStr.split('-');
    if (parts.length === 3) {
        return `${parts[2].padStart(2, '0')}/${parts[1].padStart(2, '0')}/${parts[0]}`;
    }
    return dateStr;
}

// 1. Leaflet Harita İlklendirme
function initMap() {
    const mapElement = document.getElementById('leafletMap');
    if (!mapElement) return;

    map = L.map('leafletMap').setView([40.6530, 29.3520], 15);

    const osmLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; Çiftlikköy Belediyesi MBS'
    });

    const esriSatLayer = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
        maxZoom: 19,
        attribution: 'Esri Satellite'
    });

    esriSatLayer.addTo(map);

    const baseMaps = {
        "Uydu Görüntüsü": esriSatLayer,
        "Sokak Haritası": osmLayer
    };

    L.control.layers(baseMaps).addTo(map);
    markersLayer = L.layerGroup().addTo(map);
}

// 2. Harita Markerlarını Yükleme
async function fetchMapMarkers(q = '') {
    const listEl = document.getElementById('resultsList');
    const badgeEl = document.getElementById('resultCountBadge');
    const mezarlikId = document.getElementById('mezarlikSelect') ? document.getElementById('mezarlikSelect').value : 0;

    if (!q || q.length < 2) {
        if (markersLayer) markersLayer.clearLayers();
        if (badgeEl) badgeEl.innerText = '0 Kayıt';
        if (listEl) {
            listEl.innerHTML = `
                <div style="text-align: center; color: var(--text-muted); padding: 3rem 1rem;">
                    Arama yapmak için vefat edenin adını veya soyadını giriniz.
                </div>
            `;
        }
        return;
    }

    if (listEl) {
        listEl.innerHTML = `
            <div style="text-align: center; color: var(--accent-cyan); padding: 2rem 1rem;">
                Aranıyor...
            </div>
        `;
    }

    try {
        const apiUrl = getApiPath('vatandas_sorgu', q, mezarlikId);
        const response = await fetch(apiUrl);
        const json = await response.json();
        
        if (json.status === 'success') {
            renderMapPins(json.data);
            renderSearchResults(json.data);
        } else {
            if (listEl) {
                listEl.innerHTML = `<div style="text-align: center; color: #f87171; padding: 2rem 1rem;">Hata: ${json.message || 'Sorgulama yapılamadı'}</div>`;
            }
        }
    } catch (err) {
        console.error('Harita verisi çekme hatası:', err);
        if (listEl) {
            listEl.innerHTML = `<div style="text-align: center; color: #f87171; padding: 2rem 1rem;">Sunucuya bağlanılamadı. Lütfen API bağlantısını kontrol edin.</div>`;
        }
    }
}

// 3. Pinetleri Çizme (QR Kod & Yol Tarifi Butonlu)
function renderMapPins(data) {
    if (!markersLayer) return;
    markersLayer.clearLayers();

    data.forEach(item => {
        if (item.lat && item.lng) {
            const fmtDate = formatDate(item.defin_tarihi);
            const navUrl = `https://www.google.com/maps/dir/?api=1&destination=${item.lat},${item.lng}`;
            const qrUrl = `api/index.php?action=qr_code&id=${item.id}`;

            const marker = L.marker([item.lat, item.lng])
                .bindPopup(`
                    <div style="color: #1e293b; font-family: sans-serif; padding: 6px; text-align: center;">
                        <h4 style="margin:0 0 6px 0; color: #1e3a8a; font-size:14px;">${item.ad} ${item.soyad}</h4>
                        <p style="margin:2px 0; font-size: 12px; color: #475569;"><b>Mezarlık:</b> ${item.mezarlik_adi || 'Yeni Mezarlık'}</p>
                        <p style="margin:2px 0; font-size: 12px; color: #475569;"><b>Ada/Parsel:</b> Ada ${item.ada_no || '-'} / Parsel ${item.parsel_no || '-'}</p>
                        <p style="margin:2px 0 8px 0; font-size: 12px; color: #475569;"><b>Defin Tarihi:</b> ${fmtDate}</p>
                        <div style="margin-bottom:8px;">
                            <img src="${qrUrl}" alt="Mezar QR" style="width:90px; height:90px; border:1px solid #cbd5e1; border-radius:6px; padding:2px; background:#fff;">
                        </div>
                        <a href="${navUrl}" target="_blank" style="display:inline-flex; align-items:center; gap:4px; background:#1e3a8a; color:#fff; padding:6px 12px; border-radius:4px; font-size:11px; font-weight:bold; text-decoration:none;">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"/></svg>
                            Google Maps Yol Tarifi
                        </a>
                    </div>
                `);
            markersLayer.addLayer(marker);
        }
    });

    if (data.length > 0 && data[0].lat && data[0].lng) {
        map.setView([data[0].lat, data[0].lng], 16, { animate: true });
    }
}

// 4. Arama Sonuçlarını Listeleme (SVG İkonlu & Yol Tarifi / QR Butonlu)
function renderSearchResults(results) {
    const listEl = document.getElementById('resultsList');
    const badgeEl = document.getElementById('resultCountBadge');

    if (!listEl) return;
    if (badgeEl) badgeEl.innerText = `${results.length} Kayıt`;

    if (results.length === 0) {
        listEl.innerHTML = `
            <div style="text-align: center; color: var(--text-muted); padding: 2rem 1rem;">
                Aradığınız isim kriterine uygun vefat kaydı bulunamadı.
            </div>
        `;
        return;
    }

    const mapPinSvg = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px; margin-right:2px;"><path d="M12 22s-8-4.5-8-11.8A8 8 0 0 1 12 2a8 8 0 0 1 8 8.2c0 7.3-8 11.8-8 11.8z"/><circle cx="12" cy="10" r="3"/></svg>`;

    let html = '';
    results.forEach(item => {
        const fmtDate = formatDate(item.defin_tarihi);
        const hasCoords = item.lat && item.lng && parseFloat(item.lat) !== 0 && parseFloat(item.lng) !== 0;
        const navUrl = hasCoords ? `https://www.google.com/maps/dir/?api=1&destination=${item.lat},${item.lng}` : '#';
        const qrUrl = `api/index.php?action=qr_code&id=${item.id}`;

        html += `
            <div class="bg-white rounded-2xl p-4 border border-slate-100 shadow-sm hover:shadow-md hover:border-brand-blue/30 transition cursor-pointer flex gap-4 items-start" onclick="focusOnGrave(${item.lat}, ${item.lng}, '${item.ad} ${item.soyad}')">
                <div class="flex-1">
                    <div class="flex justify-between items-start mb-2">
                        <h4 class="font-bold text-slate-800 text-lg leading-none">${item.ad} ${item.soyad}</h4>
                        <span class="bg-blue-50 text-brand-blue text-[10px] font-bold px-2 py-1 rounded-md uppercase tracking-wider">${fmtDate}</span>
                    </div>
                    <div class="text-xs text-slate-500 font-semibold mb-3 flex flex-wrap gap-x-3 gap-y-1">
                        <span class="flex items-center gap-1">${mapPinSvg} ${item.mezarlik_adi || 'Yeni Mezarlık'}</span>
                        <span class="text-slate-300">•</span>
                        <span>Ada: ${item.ada_no || '-'}</span>
                        <span class="text-slate-300">•</span>
                        <span>Parsel: ${item.parsel_no || '-'}</span>
                    </div>
                    <div class="flex flex-wrap gap-2" onclick="event.stopPropagation();">
                        <a href="mefta.html?id=${item.id}" target="_blank" class="inline-flex items-center gap-1.5 bg-brand-navy hover:bg-slate-800 text-white text-[11px] font-bold py-1.5 px-3 rounded-lg transition">
                            <i class="fa-solid fa-external-link-alt"></i> Sayfası
                        </a>
                        ${hasCoords ? `
                            <a href="${navUrl}" target="_blank" class="inline-flex items-center gap-1.5 bg-brand-emerald hover:bg-green-700 text-white text-[11px] font-bold py-1.5 px-3 rounded-lg transition">
                                <i class="fa-solid fa-location-arrow"></i> Yol Tarifi
                            </a>
                        ` : ''}
                    </div>
                </div>
                <div class="shrink-0 flex flex-col items-center justify-center bg-slate-50 p-2 rounded-xl border border-slate-100" onclick="event.stopPropagation();">
                    <img src="${qrUrl}" alt="QR" class="w-12 h-12 mix-blend-multiply">
                    <span class="text-[9px] font-bold text-slate-400 mt-1 uppercase">QR Kod</span>
                </div>
            </div>
        `;
    });

    listEl.innerHTML = html;
}

// 5. Seçilen Mezara Haritada Odaklanma & Mobilde Haritaya Kaydırma
window.focusOnGrave = function(lat, lng, name) {
    if (map && lat && lng) {
        map.setView([lat, lng], 18, { animate: true });
        L.popup()
            .setLatLng([lat, lng])
            .setContent(`<b style="color:#0284c7">${name}</b><br>Mezar Konumu`)
            .openOn(map);

        if (window.innerWidth <= 1024) {
            const mapEl = document.getElementById('leafletMap');
            if (mapEl) {
                mapEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }
    }
};

// 6. Arama Etkinlikleri
function initSearchEvents() {
    const searchBtn = document.getElementById('searchBtn');
    const searchInput = document.getElementById('searchInput');

    if (searchBtn && searchInput) {
        searchBtn.addEventListener('click', () => {
            const query = searchInput.value.trim();
            fetchMapMarkers(query);
        });

        searchInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                fetchMapMarkers(searchInput.value.trim());
            }
        });
    }
}

// 7. Genel İstatistikler
async function loadInitialStats() {
    try {
        const res = await fetch(getApiPath('stats'));
        const json = await res.json();
        if (json.status === 'success' && json.stats) {
            const el = document.getElementById('statTotalDefin');
            if (el) el.innerText = json.stats.toplam_defin.toLocaleString();
        }
    } catch(e) {}
}

// 8. QR Kod Okutulduğunda Otomatik Açılan Detay Modalı (index.html?id=ID)
async function checkUrlQrId() {
    const urlParams = new URLSearchParams(window.location.search);
    const id = urlParams.get('id');
    if (!id) return;

    try {
        const res = await fetch(`api/index.php?action=vatandas_sorgu&id=${encodeURIComponent(id)}`);
        const json = await res.json();
        if (json.status === 'success' && json.data && json.data.length > 0) {
            const item = json.data.find(x => x.id == id) || json.data[0];
            openPublicDetailModal(item);
        }
    } catch(e) {
        console.error('QR detay alma hatası:', e);
    }
}

function openPublicDetailModal(item) {
    const modal = document.getElementById('publicDetailModal');
    if (!modal) return;

    document.getElementById('pubFullName').textContent = `${item.ad} ${item.soyad}`;
    document.getElementById('pubBaba').textContent = item.baba_adi || '—';
    document.getElementById('pubAnne').textContent = item.anne_adi || '—';

    const dogumTarihi = item.dogum_tarihi || '—';
    const dogumYeri = item.dogum_yeri || 'Çiftlikköy';
    document.getElementById('pubDogum').textContent = `${dogumYeri} (${dogumTarihi})`;

    const olumTarihi = item.olum_tarihi || '—';
    const definTarihi = formatDate(item.defin_tarihi) || '—';
    document.getElementById('pubOlum').textContent = `${olumTarihi} / ${definTarihi}`;

    const mezarlik = item.mezarlik_adi || 'Yeni Mezarlık';
    const ada = item.ada_no || '—';
    const parsel = item.parsel_no || '—';
    document.getElementById('pubMezarlik').textContent = `${mezarlik} (Ada: ${ada} / Parsel: ${parsel})`;

    const pubGorselBox = document.getElementById('pubGorselBox');
    const pubGorselImg = document.getElementById('pubGorselImg');
    if (item.gorsel_url) {
        if (pubGorselImg) pubGorselImg.src = item.gorsel_url;
        if (pubGorselBox) pubGorselBox.style.display = 'block';
    } else {
        if (pubGorselBox) pubGorselBox.style.display = 'none';
    }

    const lat = parseFloat(item.lat);
    const lng = parseFloat(item.lng);
    const navContainer = document.getElementById('pubNavContainer');
    const navBtn = document.getElementById('pubNavBtn');

    if (lat && lng && !isNaN(lat) && !isNaN(lng) && lat !== 0 && lng !== 0) {
        navBtn.href = `https://www.google.com/maps/dir/?api=1&destination=${lat},${lng}`;
        navContainer.style.display = 'block';

        // Haritayı bu koordinata odakla
        if (map) {
            map.setView([lat, lng], 18, { animate: true });
            L.popup()
                .setLatLng([lat, lng])
                .setContent(`<b style="color:#1e3a8a; font-size:14px;">${item.ad} ${item.soyad}</b><br>Mezar Yeri Konumu`)
                .openOn(map);
        }
    } else {
        navContainer.style.display = 'none';
    }

    modal.style.display = 'flex';
}

function closePublicDetailModal() {
    const modal = document.getElementById('publicDetailModal');
    if (modal) modal.style.display = 'none';
}
