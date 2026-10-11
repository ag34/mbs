// admin/js/ui.js
// Kurumsal SweetAlert Tema Ayarı (Lacivert/Bordo/Turkuaz Paleti)
const SwalCorporate = Swal.mixin({
    confirmButtonColor: '#1e3a8a', // brand-navy
    cancelButtonColor: '#64748b',  // slate-500
    denyButtonColor: '#991b1b'     // brand-red
});
// Global Swal referansını override et ki tüm uygulamada kurumsal renkler geçsin
window.Swal = SwalCorporate;

document.addEventListener('alpine:init', () => {
    Alpine.data('adminApp', () => ({
        sidebarOpen: window.innerWidth > 768,
        activePage: 'dashboard', // 'dashboard', 'meftalar', 'users', 'settings'
        activeTab: 'kimlik', // form tabs
        isModalOpen: false,
        modalMode: 'add',
        isMezarlikModalOpen: false,
        mezarlikModalMode: 'add',
        editMezarlikForm: { id: null, ad: '', aciklama: '', tarihce: '', kapasite: '', alan_m2: '' },
        isDeleteConfirmOpen: false,
        isPermissionModalOpen: false,
        selectedUserForPermission: null,
        isDocTemplateModalOpen: false,
        docTemplateRecordId: null,
        docFields: {
            tc_no: true,
            baba_adi: true,
            anne_adi: true,
            defin_tarihi: true,
            dogum_tarihi: true,
            il_ilce: true,
            yakin_bilgisi: true,
            muvafakatname: true,
            sol_imza: true,
            sag_imza: true,
            sag_imza_tipi: 'mudur'
        },
        

        currentUser: {
            username: localStorage.getItem('mbs_admin_user') || '',
            role: localStorage.getItem('mbs_admin_role') || '',
            ad_soyad: localStorage.getItem('mbs_admin_name') || '',
            can_add: parseInt(localStorage.getItem('mbs_can_add') || '1'),
            can_edit: parseInt(localStorage.getItem('mbs_can_edit') || '1'),
            can_delete: parseInt(localStorage.getItem('mbs_can_delete') || '1')
        },

        get isAdmin() {
            return this.currentUser.role === 'superadmin';
        },

        systemSettings: {
            belediye_adi: 'Çiftlikköy Belediyesi',
            logo_url: 'https://www.yalovaciftlikkoy.bel.tr/upload/temelbilgiler/1717139886.svg',
            ip_restriction_enabled: false,
            allowed_ips: '',
            kiosk_ip_restriction_enabled: false,
            kiosk_allowed_ips: '',
            vatansms_api_id: '',
            vatansms_api_key: '',
            vatansms_sender: '',
            smtp_host: '',
            smtp_port: '',
            smtp_user: '',
            smtp_pass: '',
            smtp_secure: '',
            '2fa_method': 'sms',
            birim_sorumlusu_ad: '',
            birim_sorumlusu_unvan: '',
            mezarlik_sorumlusu_ad: '',
            mezarlik_sorumlusu_unvan: '',
            mudurluk_adi: ''
        },
        kioskAds: [],
        
        searchQuery: '',
        onayFilter: '',
        mezarlikId: '0',
        activeSettingsTab: 'general',
        meftalar: [],
        mezarliklar: [],
        isMezarliklarLoading: false,
        newMezarlikAd: '',
        isLoading: false,
        selectedIds: [],
        
        pagination: {
            currentPage: 1,
            totalPages: 1,
            totalRecords: 0,
            limit: 20
        },
        
        users: [],
        isUsersLoading: false,
        userModalMode: 'add',
        newUserForm: {
            id: null,
            username: '',
            password: '',
            ad_soyad: '',
            unvan: '',
            telefon: '',
            eposta: ''
        },
        
        stats: {
            total: 0,
            thisMonth: 0,
            today: 0
        },

        formData: {
            id: null, ad: '', soyad: '', tc_no: '', baba_adi: '', anne_adi: '',
            dogum_yeri: 'Çiftlikköy', dogum_tarihi: '', olum_tarihi: '', belge_no: '',
            olum_nedeni: 'DOĞAL ÖLÜM', mezarlik_id: 1, defin_bolum: 'SIRADAN',
            ada_no: '', parsel_no: '', defin_tarihi: '',
            yakin_ad_soyad: '', yakin_akrabalik: '', yakin_telefon: '', yakin_adres: '',
            gorsel_urls: [], lat: 40.6520, lng: 29.3520
        },

        mapInstance: null,
        markerInstance: null,

        dashboardMapInstance: null,

        // Iframe Preview State
        isPreviewModalOpen: false,
        previewUrl: '',
        isPreviewLoading: false,

        // Sayım Modülü State
        sayimDraft: {
            mezarlik_id: '',
            ad: '', soyad: '', ada_no: '', parsel_no: '', dogum_tarihi: '', olum_tarihi: '',
            lat: '', lng: '', gorsel_url_list: [],
            katli_mezar: 'yok', aciklama: ''
        },
        sayimMapInstance: null,
        sayimMarkerInstance: null,
        sayimIsUploading: false,
        sayimIsSaving: false,
        previewUrl: '',
        isPreviewLoading: false,
        raporData: {},
        raporLoading: false,

        async init() {
            this.checkAuth();
            this.loadSettings();
            this.loadUsers();
            this.loadMezarliklar();
            await this.loadData();
            
            let savedDocFields = localStorage.getItem('mbs_doc_template');
            if (savedDocFields) {
                try {
                    this.docFields = { ...this.docFields, ...JSON.parse(savedDocFields) };
                } catch(e) {}
            }
            
            // Watch active tab to refresh map size if user switches to defin tab
            this.$watch('activeTab', value => {
                if (value === 'defin' && this.mapInstance) {
                    setTimeout(() => {
                        this.mapInstance.invalidateSize();
                    }, 200);
                }
            });

            this.$watch('activePage', value => {
                if (value === 'dashboard') {
                    setTimeout(() => {
                        if (this.dashboardMapInstance) this.dashboardMapInstance.invalidateSize();
                    }, 200);
                } else if (value === 'sayim') {
                    setTimeout(() => {
                        this.initSayimMap();
                    }, 200);
                }
            });
            
            // Start map if dashboard
            setTimeout(() => {
                if (this.activePage === 'dashboard') this.initDashboardMap();
            }, 500);
        },

        async initDashboardMap() {
            if (!this.dashboardMapInstance) {
                this.dashboardMapInstance = L.map('dashboardMap', { preferCanvas: true }).setView([40.6520, 29.3520], 12);
                L.tileLayer('https://mt1.google.com/vt/lyrs=y&x={x}&y={y}&z={z}', {
                    attribution: '&copy; Google Maps'
                }).addTo(this.dashboardMapInstance);
            }

            try {
                const res = await fetch('../api/index.php?action=harita_verisi');
                const data = await res.json();
                if (data.features && data.features.length > 0) {
                    
                    // Mezarlıklara göre renk atama
                    const colors = ['#ef4444', '#3b82f6', '#10b981', '#f59e0b', '#8b5cf6', '#ec4899', '#14b8a6', '#f97316'];
                    const mezarlikColors = {};
                    let colorIndex = 0;
                    
                    data.features.forEach(f => {
                        const mName = f.properties.mezarlik_adi || 'Bilinmiyor';
                        if (!mezarlikColors[mName]) {
                            mezarlikColors[mName] = colors[colorIndex % colors.length];
                            colorIndex++;
                        }
                    });

                    // Önceki katmanı temizle (tekrarlı yüklemeyi önlemek için)
                    if (this.dashboardGeoJsonLayer) {
                        this.dashboardMapInstance.removeLayer(this.dashboardGeoJsonLayer);
                    }
                    if (this.dashboardLegendControl) {
                        this.dashboardMapInstance.removeControl(this.dashboardLegendControl);
                    }

                    this.dashboardGeoJsonLayer = L.geoJSON(data, {
                        pointToLayer: (feature, latlng) => {
                            const color = mezarlikColors[feature.properties.mezarlik_adi || 'Bilinmiyor'];
                            return L.circleMarker(latlng, {
                                radius: 8,
                                fillColor: color,
                                color: "#ffffff",
                                weight: 2,
                                opacity: 1,
                                fillOpacity: 0.8
                            });
                        },
                        onEachFeature: (feature, layer) => {
                            const html = `
                                <div class="text-center p-1">
                                    <h4 class="font-bold text-brand-navy border-b border-slate-100 pb-1 mb-1">${feature.properties.ad_soyad}</h4>
                                    <p class="text-xs text-slate-600 font-medium mt-1"><i class="fa-solid fa-map-pin mr-1" style="color: ${mezarlikColors[feature.properties.mezarlik_adi || 'Bilinmiyor']}"></i>${feature.properties.mezarlik_adi}</p>
                                    <p class="text-xs text-slate-500 font-bold mt-1 bg-slate-50 py-1 rounded">Ada: ${feature.properties.ada || '-'} | Parsel: ${feature.properties.parsel || '-'}</p>
                                </div>
                            `;
                            layer.bindPopup(html);
                        }
                    }).addTo(this.dashboardMapInstance);
                    
                    this.dashboardMapInstance.fitBounds(this.dashboardGeoJsonLayer.getBounds(), { padding: [50, 50] });

                    // Legend (Lejant) Ekleme
                    this.dashboardLegendControl = L.control({ position: 'bottomright' });
                    this.dashboardLegendControl.onAdd = function (map) {
                        const div = L.DomUtil.create('div', 'bg-white/90 backdrop-blur-md p-4 rounded-2xl shadow-lg border border-slate-200');
                        div.innerHTML = '<h4 class="font-bold text-sm text-slate-800 border-b border-slate-100 pb-2 mb-3"><i class="fa-solid fa-map-location-dot text-brand-navy mr-2"></i>Mezarlıklar</h4>';
                        const grid = L.DomUtil.create('div', 'grid grid-cols-1 gap-2', div);
                        
                        for (const [mName, color] of Object.entries(mezarlikColors)) {
                            grid.innerHTML += `
                                <div class="flex items-center gap-3">
                                    <div class="w-4 h-4 rounded-full border-2 border-white shadow-sm shrink-0" style="background-color: ${color}"></div>
                                    <span class="text-xs font-bold text-slate-600 truncate max-w-[150px]">${mName}</span>
                                </div>
                            `;
                        }
                        return div;
                    };
                    this.dashboardLegendControl.addTo(this.dashboardMapInstance);
                }
            } catch (e) {
                console.error('Harita verisi çekilemedi:', e);
            }
        },

        openPreviewModal(id) {
            this.isPreviewLoading = true;
            this.previewUrl = `../mefta.html?id=${id}`;
            this.isPreviewModalOpen = true;
        },

        closePreviewModal() {
            this.isPreviewModalOpen = false;
            setTimeout(() => {
                this.previewUrl = '';
            }, 300);
        },

        toggleAllSelection(event) {
            if (event.target.checked) {
                this.selectedIds = this.meftalar.map(m => m.id.toString());
            } else {
                this.selectedIds = [];
            }
        },

        openDocTemplateModal(id) {
            this.docTemplateRecordId = id;
            this.isDocTemplateModalOpen = true;
        },

        saveDocTemplatePreferences() {
            localStorage.setItem('mbs_doc_template', JSON.stringify(this.docFields));
            Swal.fire({
                title: 'Başarılı',
                text: 'Şablon tercihleriniz tarayıcınıza kaydedildi. Bundan sonraki indirmelerde bu alanlar seçili gelecek.',
                icon: 'success',
                timer: 3000,
                showConfirmButton: false
            });
        },

        generateDocument(type) {
            if (!this.docTemplateRecordId) return;
            const urlParams = new URLSearchParams();
            urlParams.append('action', type === 'pdf' ? 'export_tekli_pdf' : 'export_docx');
            urlParams.append('id', this.docTemplateRecordId);
            
            // Pass the fields configuration as JSON string or individual params
            urlParams.append('fields', JSON.stringify(this.docFields));
            urlParams.append('token', localStorage.getItem('mbs_auth_token') || '');
            
            window.open(`../api/index.php?${urlParams.toString()}`, '_blank');
            this.isDocTemplateModalOpen = false;
        },


        async exportData(type) {
            if (this.selectedIds.length === 0) {
                const confirm = await Swal.fire({
                    title: 'Tümünü İndir?',
                    text: "Hiçbir kayıt seçmediniz. Tablodaki tüm veriler (filtreye göre) indirilecek. Onaylıyor musunuz?",
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Evet, İndir',
                    cancelButtonText: 'İptal'
                });
                if (!confirm.isConfirmed) return;
            }

            const urlParams = new URLSearchParams();
            urlParams.append('action', type === 'pdf' ? 'export_pdf' : 'export_excel');
            urlParams.append('admin_key', '1');
            urlParams.append('q', this.searchQuery);
            urlParams.append('mezarlik_id', this.mezarlikId);
            
            if (this.selectedIds.length > 0) {
                urlParams.append('ids', this.selectedIds.join(','));
            }
            urlParams.append('token', localStorage.getItem('mbs_auth_token') || '');

            window.open(`../api/index.php?${urlParams.toString()}`, '_blank');
        },

        printQrSingle(id) {
            const token = localStorage.getItem('mbs_auth_token') || '';
            window.open(`../api/index.php?action=print_qr_pdf&ids=${id}&token=${token}`, '_blank');
        },

        printQrBulk() {
            if (this.selectedIds.length === 0) {
                Swal.fire('Uyarı', 'Lütfen yazdırılacak kayıtları seçin.', 'warning');
                return;
            }
            const idsStr = this.selectedIds.join(',');
            const token = localStorage.getItem('mbs_auth_token') || '';
            window.open(`../api/index.php?action=print_qr_pdf&ids=${idsStr}&token=${token}`, '_blank');
        },

        isExcelImportModalOpen: false,
        excelFileToImport: null,
        excelImportLoading: false,

        openExcelImportModal() {
            this.excelFileToImport = null;
            // Dosya inputunu sıfırla
            setTimeout(() => {
                const input = document.getElementById('excelFileInput');
                if (input) input.value = '';
            }, 50);
            this.isExcelImportModalOpen = true;
        },

        closeExcelImportModal() {
            this.isExcelImportModalOpen = false;
            this.excelFileToImport = null;
        },

        handleExcelFileSelect(e) {
            if (e.target.files.length > 0) {
                this.excelFileToImport = e.target.files[0];
            } else {
                this.excelFileToImport = null;
            }
        },

        downloadExcelTemplate() {
            try {
                // Şablon verisini oluştur (Okunabilir, güzel başlıklar)
                const templateData = [
                    {
                        "Ad (Zorunlu)": "ÖRNEK",
                        "Soyad (Zorunlu)": "KİŞİ",
                        "TC Kimlik No": "11111111111",
                        "Baba Adı": "AHMET",
                        "Anne Adı": "AYŞE",
                        "Doğum Tarihi": "01.01.1950",
                        "Doğum Yeri": "YALOVA",
                        "Ölüm Tarihi": "15.06.2023",
                        "Ölüm Nedeni": "DOĞAL ÖLÜM",
                        "Defin Tarihi": "16.06.2023",
                        "Defin Bölüm": "SIRADAN",
                        "Mezarlık ID (Zorunlu)": "1",
                        "Ada No": "12",
                        "Parsel No": "45",
                        "Belge No": "BLG-12345",
                        "Yakın Ad Soyad": "MEHMET KİŞİ",
                        "Yakın Akrabalık": "OĞLU",
                        "Yakın Telefon": "05551112233",
                        "Yakın Adres": "Örnek Mah. Test Sok. No:1"
                    }
                ];

                // SheetJS ile Excel dosyası oluştur
                const worksheet = XLSX.utils.json_to_sheet(templateData);
                
                // Sütun genişliklerini ayarla (daha güzel görünmesi için)
                const wscols = [
                    {wch: 15}, {wch: 15}, {wch: 15}, {wch: 15}, {wch: 15},
                    {wch: 15}, {wch: 15}, {wch: 15}, {wch: 15}, {wch: 15},
                    {wch: 15}, {wch: 22}, {wch: 10}, {wch: 10}, {wch: 15},
                    {wch: 20}, {wch: 20}, {wch: 15}, {wch: 30}
                ];
                worksheet['!cols'] = wscols;

                const workbook = XLSX.utils.book_new();
                XLSX.utils.book_append_sheet(workbook, worksheet, "Şablon");
                
                // Sadece XLSX olarak indir (Kullanıcı talebi)
                XLSX.writeFile(workbook, "MBS_Toplu_Kayit_Sablonu.xlsx");
            } catch (error) {
                console.error("Şablon oluşturulurken hata:", error);
                Swal.fire('Hata', 'Şablon dosyası oluşturulamadı.', 'error');
            }
        },

        async submitExcelImport() {
            if (!this.excelFileToImport) {
                Swal.fire('Eksik Dosya', 'Lütfen yüklemek için bir Excel dosyası seçin.', 'warning');
                return;
            }
            
            this.excelImportLoading = true;
            
            try {
                // Excel dosyasını FileReader ile oku ve SheetJS ile parse et
                const reader = new FileReader();
                reader.onload = async (e) => {
                    try {
                        const data = new Uint8Array(e.target.result);
                        const workbook = XLSX.read(data, { type: 'array' });
                        const firstSheetName = workbook.SheetNames[0];
                        const worksheet = workbook.Sheets[firstSheetName];
                        
                        // Excel'i JSON array'e çevir
                        const rawRecords = XLSX.utils.sheet_to_json(worksheet, { defval: "" });
                        
                        if (rawRecords.length === 0) {
                            this.excelImportLoading = false;
                            Swal.fire('Hata', 'Excel dosyası boş veya okunamadı.', 'error');
                            return;
                        }

                        // Güzel başlıkları veritabanı sütunlarına eşle
                        const jsonRecords = rawRecords.map(row => ({
                            ad: row["Ad (Zorunlu)"] || row["ad (Zorunlu)"] || row["ad"] || "",
                            soyad: row["Soyad (Zorunlu)"] || row["soyad (Zorunlu)"] || row["soyad"] || "",
                            tc_no: row["TC Kimlik No"] || row["tc_no"] || "",
                            baba_adi: row["Baba Adı"] || row["baba_adi"] || "",
                            anne_adi: row["Anne Adı"] || row["anne_adi"] || "",
                            dogum_tarihi: row["Doğum Tarihi"] || row["dogum_tarihi"] || "",
                            dogum_yeri: row["Doğum Yeri"] || row["dogum_yeri"] || "",
                            olum_tarihi: row["Ölüm Tarihi"] || row["olum_tarihi"] || "",
                            olum_nedeni: row["Ölüm Nedeni"] || row["olum_nedeni"] || "",
                            defin_tarihi: row["Defin Tarihi"] || row["defin_tarihi"] || "",
                            defin_bolum: row["Defin Bölüm"] || row["defin_bolum"] || "",
                            mezarlik_id: row["Mezarlık ID (Zorunlu)"] || row["mezarlik_id (Zorunlu)"] || row["mezarlik_id"] || "",
                            ada_no_text: row["Ada No"] || row["ada_no"] || "",
                            parsel_no_text: row["Parsel No"] || row["parsel_no"] || "",
                            belge_no: row["Belge No"] || row["belge_no"] || "",
                            yakin_ad_soyad: row["Yakın Ad Soyad"] || row["yakin_ad_soyad"] || "",
                            yakin_akrabalik: row["Yakın Akrabalık"] || row["yakin_akrabalik"] || "",
                            yakin_telefon: row["Yakın Telefon"] || row["yakin_telefon"] || "",
                            yakin_adres: row["Yakın Adres"] || row["yakin_adres"] || ""
                        }));

                        // Backend'e gönder
                        const res = await fetch('../api/index.php?action=toplu_kayit_ekle', {
                            method: 'POST',
                            headers: {
                                ...apiService.getHeaders(true),
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify({ records: jsonRecords })
                        });
                        
                        const resultData = await res.json();
                        this.excelImportLoading = false;
                        
                        if (resultData.status === 'success') {
                            Swal.fire('Başarılı', resultData.message, 'success');
                            this.closeExcelImportModal();
                            this.loadData();
                        } else {
                            Swal.fire('Hata', resultData.message || 'Yükleme başarısız', 'error');
                        }
                    } catch (parseErr) {
                        this.excelImportLoading = false;
                        Swal.fire('Hata', 'Excel dosyası işlenirken bir hata oluştu.', 'error');
                    }
                };
                reader.readAsArrayBuffer(this.excelFileToImport);
                
            } catch (err) {
                this.excelImportLoading = false;
                Swal.fire('Hata', 'İşlem başlatılamadı.', 'error');
            }
        },

        importExcel() {
            this.openExcelImportModal();
        },

        checkAuth() {
            if (localStorage.getItem('mbs_admin_logged') !== 'true') {
                window.location.href = 'login.html';
            }
        },

        async loadData() {
            this.isLoading = true;
            try {
                let currentFilter = this.onayFilter;
                if (this.activePage === 'meftalar') {
                    currentFilter = 'onaylandi';
                } else if (this.activePage === 'saha_sayim_listesi') {
                    currentFilter = this.onayFilter || 'bekliyor';
                }

                const res = await apiService.fetchMeftalar(this.searchQuery, this.mezarlikId, this.pagination.currentPage, this.pagination.limit, currentFilter);
                if (res.status === 'success') {
                    this.meftalar = res.data;
                    this.pagination.totalRecords = res.totalRecords || res.count;
                    this.pagination.totalPages = res.totalPages || 1;
                    this.calculateStats();
                } else {
                    Swal.fire('Hata', 'Veriler yüklenemedi: ' + res.message, 'error');
                }
            } catch (err) {
                Swal.fire('Bağlantı Hatası', 'Sunucuya bağlanılamadı.', 'error');
            } finally {
                this.isLoading = false;
            }
        },

        goToPage(page) {
            if (page >= 1 && page <= this.pagination.totalPages) {
                this.pagination.currentPage = page;
                this.loadData();
            }
        },

        nextPage() {
            if (this.pagination.currentPage < this.pagination.totalPages) {
                this.pagination.currentPage++;
                this.loadData();
            }
        },

        prevPage() {
            if (this.pagination.currentPage > 1) {
                this.pagination.currentPage--;
                this.loadData();
            }
        },

        moveKioskAdUp(index) {
            if (index > 0) {
                const temp = this.kioskAds[index];
                this.kioskAds[index] = this.kioskAds[index - 1];
                this.kioskAds[index - 1] = temp;
                this.saveSettings();
            }
        },

        moveKioskAdDown(index) {
            if (index < this.kioskAds.length - 1) {
                const temp = this.kioskAds[index];
                this.kioskAds[index] = this.kioskAds[index + 1];
                this.kioskAds[index + 1] = temp;
                this.saveSettings();
            }
        },

        calculateStats() {
            this.stats.total = this.pagination.totalRecords || this.meftalar.length;
            // Simple logic for this month/today can be added later
        },

        openAddModal() {
            this.modalMode = 'add';
            this.resetForm();
            this.isModalOpen = true;
            this.activeTab = 'kimlik';
            this.$nextTick(() => this.initMap());
        },

        openEditModal(id) {
            const item = this.meftalar.find(x => x.id == id);
            if (!item) return;

            let parsedGorseller = [];
            if (item.gorsel_url) {
                try {
                    parsedGorseller = JSON.parse(item.gorsel_url);
                    if (!Array.isArray(parsedGorseller)) parsedGorseller = [item.gorsel_url];
                } catch (e) {
                    parsedGorseller = [item.gorsel_url];
                }
            }

            this.modalMode = 'edit';
            this.formData = {
                id: item.id,
                ad: item.ad,
                soyad: item.soyad,
                tc_no: item.tc_no || '',
                baba_adi: item.baba_adi || '',
                anne_adi: item.anne_adi || '',
                dogum_yeri: item.dogum_yeri || 'Çiftlikköy',
                dogum_tarihi: this.formatDateForInput(item.dogum_tarihi),
                olum_tarihi: this.formatDateForInput(item.olum_tarihi),
                belge_no: item.belge_no || '',
                olum_nedeni: item.olum_nedeni || 'DOĞAL ÖLÜM',
                mezarlik_id: item.mezarlik_id || 1,
                defin_bolum: item.defin_bolum || 'SIRADAN',
                ada_no: item.ada_no || '',
                parsel_no: item.parsel_no || '',
                defin_tarihi: this.formatDateForInput(item.defin_tarihi),
                yakin_ad_soyad: item.yakin_ad_soyad || '',
                yakin_akrabalik: item.yakin_akrabalik || '',
                yakin_telefon: item.yakin_telefon || '',
                yakin_adres: item.yakin_adres || '',
                gorsel_urls: parsedGorseller,
                lat: parseFloat(item.lat) || 40.6520,
                lng: parseFloat(item.lng) || 29.3520
            };
            this.isModalOpen = true;
            this.activeTab = 'kimlik';
            this.$nextTick(() => this.initMap());
        },

        closeModal() {
            this.isModalOpen = false;
            this.destroyMap();
        },

        resetForm() {
            this.formData = {
                id: null, ad: '', soyad: '', tc_no: '', baba_adi: '', anne_adi: '',
                dogum_yeri: 'Çiftlikköy', dogum_tarihi: '', olum_tarihi: '', belge_no: '',
                olum_nedeni: 'DOĞAL ÖLÜM', mezarlik_id: 1, defin_bolum: 'SIRADAN',
                ada_no: '', parsel_no: '', defin_tarihi: new Date().toISOString().split('T')[0],
                yakin_ad_soyad: '', yakin_akrabalik: '', yakin_telefon: '', yakin_adres: '',
                gorsel_urls: [], lat: 40.6520, lng: 29.3520
            };
        },

        formatDateForInput(dateStr) {
            if (!dateStr) return '';
            const parts = dateStr.split('/');
            if (parts.length === 3) return `${parts[2]}-${parts[1]}-${parts[0]}`;
            return dateStr;
        },

        async saveRecord() {
            if (!this.formData.ad || !this.formData.soyad) {
                Swal.fire('Uyarı', 'Lütfen Ad ve Soyad alanlarını doldurun.', 'warning');
                return;
            }

            try {
                Swal.fire({ title: 'Kaydediliyor...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
                
                const payload = { ...this.formData };
                payload.gorsel_url = JSON.stringify(payload.gorsel_urls);
                delete payload.gorsel_urls;

                const res = await apiService.saveMefta(payload);
                if (res.status === 'success') {
                    Swal.fire({ title: 'Başarılı', text: 'Kayıt başarıyla kaydedildi.', icon: 'success', timer: 1500, showConfirmButton: false });
                    this.loadData();
                    if (this.modalMode === 'add') {
                        this.resetForm();
                        this.activeTab = 'kimlik';
                    } else {
                        this.closeModal();
                    }
                } else {
                    Swal.fire('Hata', res.message, 'error');
                }
            } catch (err) {
                Swal.fire('Hata', 'Kaydedilirken bir hata oluştu.', 'error');
            }
        },

        async deleteRecord(id) {
            if (!this.isAdmin) {
                Swal.fire('Hata', 'Bu işlem için yetkiniz yok.', 'error');
                return;
            }
            
            const confirm = await Swal.fire({
                title: 'Emin misiniz?',
                text: "Bu işlem geri alınamaz! Kaydı silmek istediğinize emin misiniz?",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Evet, Sil!',
                cancelButtonText: 'İptal'
            });

            if (confirm.isConfirmed) {
                try {
                    const res = await apiService.deleteMefta(id);
                    if (res.status === 'success') {
                        Swal.fire('Silindi!', res.message, 'success');
                        if (this.isModalOpen) this.closeModal();
                        this.loadData();
                    } else {
                        Swal.fire('Hata', res.message, 'error');
                    }
                } catch (err) {
                    Swal.fire('Hata', 'Silinirken bir hata oluştu.', 'error');
                }
            }
        },

        async handleImageUpload(event) {
            const files = event.target.files;
            if (!files || files.length === 0) return;

            try {
                let uploadedCount = 0;
                for (let i = 0; i < files.length; i++) {
                    let file = files[i];
                    if (!file.type.startsWith('image/')) continue;

                    Swal.fire({ title: `Resim ${i+1}/${files.length} Hazırlanıyor...`, allowOutsideClick: false, didOpen: () => Swal.showLoading() });
                    try {
                        file = await this.compressImage(file);
                    } catch (err) {
                        Swal.fire('Hata', `Resim okunamadı veya desteklenmeyen format: ${file.name}`, 'error');
                        continue;
                    }
                    
                    Swal.fire({ title: `Sunucuya Yükleniyor (${i+1}/${files.length})...`, allowOutsideClick: false, didOpen: () => Swal.showLoading() });
                    const res = await apiService.uploadImage(file);
                    
                    if (res.status === 'success') {
                        this.formData.gorsel_urls.push(res.url);
                        uploadedCount++;
                    } else {
                        Swal.fire('Hata', res.message, 'error');
                        return;
                    }
                }
                
                if (uploadedCount > 0) {
                    Swal.fire({ icon: 'success', title: 'Tamamlandı!', text: `${uploadedCount} görsel başarıyla yüklendi.`, timer: 2000, showConfirmButton: false });
                } else {
                    Swal.close();
                }
                event.target.value = ''; // Reset input
            } catch (err) {
                Swal.fire('Hata', 'Görseller yüklenirken bir sorun oluştu.', 'error');
            }
        },
        
        removeImage(index) {
            this.formData.gorsel_urls.splice(index, 1);
        },

        async updateRecordStatus(id, newStatus) {
            if (!this.isAdmin) return;
            const confirm = await Swal.fire({
                title: 'Emin misiniz?',
                text: "Bu kaydı onaylamak ve yayına almak istediğinize emin misiniz?",
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Evet, Onayla',
                cancelButtonText: 'İptal'
            });
            if (confirm.isConfirmed) {
                try {
                    const res = await fetch('../api/index.php?action=onay_guncelle', {
                        method: 'POST',
                        headers: apiService.getHeaders(),
                        body: JSON.stringify({ id: id, onay_durumu: newStatus })
                    });
                    const data = await res.json();
                    if (data.status === 'success') {
                        Swal.fire('Başarılı', data.message, 'success');
                        this.loadData();
                    } else {
                        Swal.fire('Hata', data.message, 'error');
                    }
                } catch(err) {
                    Swal.fire('Hata', 'İşlem sırasında bir hata oluştu.', 'error');
                }
            }
        },

        async bulkApprove(newStatus) {
            if (!this.isAdmin || this.selectedIds.length === 0) return;
            const label = newStatus === 'onaylandi' ? 'onaylamak' : 'reddetmek';
            const confirm = await Swal.fire({
                title: `${this.selectedIds.length} Kayıt`,
                text: `Seçili ${this.selectedIds.length} kaydı ${label} istediğinize emin misiniz?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: newStatus === 'onaylandi' ? 'Evet, Onayla' : 'Evet, Reddet',
                cancelButtonText: 'İptal',
                confirmButtonColor: newStatus === 'onaylandi' ? '#059669' : '#dc2626'
            });
            if (confirm.isConfirmed) {
                try {
                    const res = await fetch('../api/index.php?action=onay_guncelle', {
                        method: 'POST',
                        headers: apiService.getHeaders(),
                        body: JSON.stringify({ ids: this.selectedIds.join(','), onay_durumu: newStatus })
                    });
                    const data = await res.json();
                    if (data.status === 'success') {
                        Swal.fire('Başarılı', data.message, 'success');
                        this.selectedIds = [];
                        this.loadData();
                    } else {
                        Swal.fire('Hata', data.message, 'error');
                    }
                } catch(err) {
                    Swal.fire('Hata', 'İşlem sırasında bir hata oluştu.', 'error');
                }
            }
        },

        initSayimMap() {
            if (this.sayimMapInstance) {
                this.sayimMapInstance.invalidateSize();
                return;
            }
            this.sayimMapInstance = L.map('sayimMap', {
                dragging: !L.Browser.mobile,
                tap: !L.Browser.mobile
            }).setView([40.6520, 29.3520], 13);
            L.tileLayer('https://{s}.google.com/vt/lyrs=y&x={x}&y={y}&z={z}', {
                maxZoom: 22, subdomains: ['mt0', 'mt1', 'mt2', 'mt3']
            }).addTo(this.sayimMapInstance);

            if (this.sayimDraft.lat && this.sayimDraft.lng) {
                this.setSayimMarker(parseFloat(this.sayimDraft.lat), parseFloat(this.sayimDraft.lng));
                this.sayimMapInstance.setView([this.sayimDraft.lat, this.sayimDraft.lng], 19);
            }

            this.sayimMapInstance.on('click', (e) => {
                this.setSayimMarker(e.latlng.lat, e.latlng.lng);
            });
        },

        setSayimMarker(lat, lng) {
            if (this.sayimMarkerInstance) this.sayimMapInstance.removeLayer(this.sayimMarkerInstance);
            this.sayimMarkerInstance = L.marker([lat, lng]).addTo(this.sayimMapInstance);
            this.sayimDraft.lat = lat.toFixed(6);
            this.sayimDraft.lng = lng.toFixed(6);
        },

        getSayimLocation() {
            if (!navigator.geolocation) {
                Swal.fire('Hata', 'Cihazınız konum servisini desteklemiyor.', 'error');
                return;
            }
            navigator.geolocation.getCurrentPosition(
                (pos) => {
                    this.setSayimMarker(pos.coords.latitude, pos.coords.longitude);
                    this.sayimMapInstance.setView([pos.coords.latitude, pos.coords.longitude], 20);
                },
                (err) => {
                    let msg = 'Konum alınamadı.';
                    if (err.code === 1) msg = 'Konum izni reddedildi. Lütfen tarayıcı ayarlarından izin verin.';
                    else if (err.code === 2) msg = 'Konum servisi kullanılamıyor (cihazınızda kapalı olabilir veya HTTPS gerekebilir).';
                    else if (err.code === 3) msg = 'Konum alma isteği zaman aşımına uğradı.';
                    
                    Swal.fire({
                        title: 'Konum Hatası',
                        html: msg + '<br><br><b class="text-brand-navy">İpucu:</b> Harita üzerinde ilgili noktaya tıklayarak konumu <b>manuel</b> de belirleyebilirsiniz.',
                        icon: 'warning'
                    });
                },
                { enableHighAccuracy: true, timeout: 10000 }
            );
        },

        async uploadSayimPhotos(e) {
            const files = e.target.files;
            if (!files || files.length === 0) return;
            this.sayimIsUploading = true;
            try {
                for (let i = 0; i < files.length; i++) {
                    let file = files[i];
                    if (!file.type.startsWith('image/')) continue;
                    
                    try {
                        file = await this.compressImage(file);
                    } catch (err) {
                        continue; // skip bad image
                    }
                    
                    const res = await apiService.uploadImage(file);
                    if (res.status === 'success') {
                        this.sayimDraft.gorsel_url_list.push(res.url);
                    }
                }
            } catch (error) {
                Swal.fire('Hata', 'Fotoğraf yüklenemedi: ' + error.message, 'error');
            }
            this.sayimIsUploading = false;
            e.target.value = ''; // Reset input
        },

        clearSayimForm() {
            if(confirm('Formu tamamen temizlemek (Mezarlık ve Konum hariç) istediğinize emin misiniz?')) {
                const mId = this.sayimDraft.mezarlik_id; // Keep mezarlik
                const lat = this.sayimDraft.lat; // Keep lat
                const lng = this.sayimDraft.lng; // Keep lng
                this.sayimDraft = {
                    mezarlik_id: mId, ad: '', soyad: '', ada_no: '', parsel_no: '',
                    dogum_tarihi: '', olum_tarihi: '', lat: lat, lng: lng, gorsel_url_list: [],
                    katli_mezar: 'yok', aciklama: ''
                };
                if (document.getElementById("sayimPhotoInput")) document.getElementById("sayimPhotoInput").value = "";
            }
        },

        async saveSayimRecord(force = false) {
            if (!this.sayimDraft.mezarlik_id || !this.sayimDraft.ad || !this.sayimDraft.soyad || !this.sayimDraft.lat) {
                Swal.fire('Uyarı', 'Lütfen yıldızlı (*) zorunlu alanları doldurun.', 'warning');
                return;
            }

            this.sayimIsSaving = true;
            try {
                const payload = {
                    ...this.sayimDraft,
                    ad: this.sayimDraft.ad.toUpperCase(),
                    soyad: this.sayimDraft.soyad.toUpperCase(),
                    ada_no: this.sayimDraft.ada_no.toUpperCase(),
                    parsel_no: this.sayimDraft.parsel_no.toUpperCase(),
                    gorsel_url: this.sayimDraft.gorsel_url_list.length > 0 ? JSON.stringify(this.sayimDraft.gorsel_url_list) : '',
                    aciklama: (this.sayimDraft.katli_mezar === 'var' ? 'Katlı Mezar. ' : '') + this.sayimDraft.aciklama.toUpperCase(),
                    ignore_duplicate: force ? 1 : 0,
                    onay_durumu: 'bekliyor' // Force to pending state
                };

                const res = await fetch('../api/index.php?action=defin_ekle', {
                    method: 'POST',
                    headers: apiService.getHeaders(),
                    body: JSON.stringify(payload)
                });
                
                if (res.status === 409) {
                    const data = await res.json();
                    if (confirm(data.message)) {
                        return this.saveSayimRecord(true); // Force save
                    }
                    this.sayimIsSaving = false;
                    return;
                }

                const data = await res.json();
                if (data.status === 'success' || data.status === 201) {
                    Swal.fire({
                        title: 'Başarılı!',
                        text: 'Kayıt onay bekliyor listesine eklendi.',
                        icon: 'success',
                        timer: 1500,
                        showConfirmButton: false
                    });
                    
                    const mId = this.sayimDraft.mezarlik_id;
                    
                    this.sayimDraft = {
                        mezarlik_id: mId, ad: '', soyad: '', ada_no: '', parsel_no: '',
                        dogum_tarihi: '', olum_tarihi: '', lat: '', lng: '', gorsel_url_list: [],
                        katli_mezar: 'yok', aciklama: ''
                    };
                    if (document.getElementById("sayimPhotoInput")) document.getElementById("sayimPhotoInput").value = "";
                    
                    this.loadData();
                } else {
                    throw new Error(data.message);
                }
            } catch (error) {
                Swal.fire('Hata', error.message || 'Kayıt başarısız.', 'error');
            }
            this.sayimIsSaving = false;
        },

        handleLogoUpload(e) {
            const file = e.target.files[0];
            if (!file) return;
            const reader = new FileReader();
            reader.onload = (e) => {
                this.systemSettings.logo_url = e.target.result;
            };
            reader.readAsDataURL(file);
        },

        openMezarlikModal(mode, item = null) {
            this.mezarlikModalMode = mode;
            if (mode === 'edit' && item) {
                this.editMezarlikForm = { 
                    id: item.id, 
                    ad: item.ad, 
                    aciklama: item.aciklama || '', 
                    tarihce: item.tarihce || '',
                    kapasite: item.kapasite || '',
                    alan_m2: item.alan_m2 || ''
                };
            } else {
                this.editMezarlikForm = { id: null, ad: this.newMezarlikAd || '', aciklama: '', tarihce: '', kapasite: '', alan_m2: '' };
            }
            this.isMezarlikModalOpen = true;
        },

        closeMezarlikModal() {
            this.isMezarlikModalOpen = false;
        },

        async addMyIp() {
            try {
                const res = await fetch('https://api64.ipify.org?format=json');
                const data = await res.json();
                if (data.ip) {
                    let currentIps = this.systemSettings.allowed_ips || '';
                    // Hem virgül hem de yeni satırla bölünmüşleri temizleyip diziye alalım
                    let ipList = currentIps.split(/[\s,]+/).map(i => i.trim()).filter(i => i);
                    if (!ipList.includes(data.ip)) {
                        ipList.push(data.ip);
                        this.systemSettings.allowed_ips = ipList.join('\n');
                        Swal.fire({
                            toast: true,
                            position: 'top-end',
                            icon: 'success',
                            title: `IP Adresiniz (${data.ip}) eklendi`,
                            showConfirmButton: false,
                            timer: 3000
                        });
                    } else {
                        Swal.fire({
                            toast: true,
                            position: 'top-end',
                            icon: 'info',
                            title: 'Bu IP adresi zaten listede.',
                            showConfirmButton: false,
                            timer: 2000
                        });
                    }
                }
            } catch (err) {
                Swal.fire('Hata', 'IP adresiniz tespit edilemedi.', 'error');
            }
        },

        async saveMezarlik() {
            if (!this.editMezarlikForm.ad) {
                Swal.fire('Hata', 'Mezarlık Adı zorunludur.', 'warning');
                return;
            }
            try {
                Swal.fire({ title: 'Kaydediliyor...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
                const action = this.mezarlikModalMode === 'edit' ? 'mezarlik_duzenle' : 'mezarlik_ekle';
                const res = await fetch(`../api/index.php?action=${action}`, {
                    method: 'POST',
                    headers: apiService.getHeaders(),
                    body: JSON.stringify(this.editMezarlikForm)
                });
                if (res.status === 401) { window.location.href = 'login.html'; return; }
                const data = await res.json();
                if (data.status === 'success') {
                    Swal.fire('Başarılı', data.message, 'success');
                    this.closeMezarlikModal();
                    this.newMezarlikAd = '';
                    this.loadMezarliklar();
                    this.dashboardMapInstance = null;
                } else {
                    Swal.fire('Hata', data.message, 'error');
                }
            } catch (err) {
                Swal.fire('Hata', 'Sunucuya bağlanılamadı.', 'error');
            }
        },

        async deleteMezarlik(id, ad) {
            const result = await Swal.fire({
                title: 'Emin misiniz?',
                text: `${ad} kalıcı olarak silinecek.`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#64748b',
                confirmButtonText: 'Evet, Sil!',
                cancelButtonText: 'İptal'
            });

            if (result.isConfirmed) {
                try {
                    Swal.fire({ title: 'Siliniyor...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
                    const res = await fetch('../api/index.php?action=mezarlik_sil', {
                        method: 'POST',
                        headers: apiService.getHeaders(),
                        body: JSON.stringify({ id })
                    });
                    if (res.status === 401) { window.location.href = 'login.html'; return; }
                    const data = await res.json();
                    if (data.status === 'success') {
                        Swal.fire('Silindi', data.message, 'success');
                        this.loadMezarliklar();
                    } else {
                        Swal.fire('Hata', data.message, 'error');
                    }
                } catch (err) {
                    Swal.fire('Hata', 'Sunucuya bağlanılamadı.', 'error');
                }
            }
        },

        compressImage(file) {
            return new Promise((resolve) => {
                const reader = new FileReader();
                reader.onload = (e) => {
                    const img = new Image();
                    img.onload = () => {
                        const canvas = document.createElement('canvas');
                        const maxDim = 1200;
                        let width = img.width;
                        let height = img.height;
                        if (width > maxDim || height > maxDim) {
                            if (width > height) {
                                height = Math.round(height * (maxDim / width));
                                width = maxDim;
                            } else {
                                width = Math.round(width * (maxDim / height));
                                height = maxDim;
                            }
                        }
                        canvas.width = width;
                        canvas.height = height;
                        const ctx = canvas.getContext('2d');
                        ctx.drawImage(img, 0, 0, width, height);
                        canvas.toBlob((blob) => {
                            if (blob) {
                                resolve(new File([blob], file.name.replace(/\.[^/.]+$/, "") + ".jpg", { type: 'image/jpeg' }));
                            } else {
                                resolve(file);
                            }
                        }, 'image/jpeg', 0.85);
                    };
                    img.onerror = () => reject(new Error('Resim okunamadı.'));
                    img.src = e.target.result;
                };
                reader.onerror = () => resolve(file);
                reader.readAsDataURL(file);
            });
        },

        initMap() {
            setTimeout(() => {
                const mapContainer = document.getElementById('modalMap');
                if (!mapContainer) return;

                if (this.mapInstance) {
                    this.mapInstance.remove();
                    this.mapInstance = null;
                }
                
                if (mapContainer._leaflet_id) mapContainer._leaflet_id = null;

                const lat = this.formData.lat || 40.6520;
                const lng = this.formData.lng || 29.3520;

                this.mapInstance = L.map('modalMap', {scrollWheelZoom: true}).setView([lat, lng], 19);
                L.tileLayer('https://mt1.google.com/vt/lyrs=y&x={x}&y={y}&z={z}', {
                    attribution: '&copy; Google Maps',
                    maxZoom: 22
                }).addTo(this.mapInstance);

                this.markerInstance = L.marker([lat, lng], { draggable: true }).addTo(this.mapInstance);

                this.markerInstance.on('dragend', (e) => {
                    const pos = e.target.getLatLng();
                    this.formData.lat = pos.lat.toFixed(8);
                    this.formData.lng = pos.lng.toFixed(8);
                });

                this.mapInstance.on('click', (e) => {
                    this.formData.lat = e.latlng.lat.toFixed(8);
                    this.formData.lng = e.latlng.lng.toFixed(8);
                    this.markerInstance.setLatLng(e.latlng);
                });

                setTimeout(() => this.mapInstance.invalidateSize(), 200);
            }, 300);
        },

        destroyMap() {
            if (this.mapInstance) {
                this.mapInstance.remove();
                this.mapInstance = null;
            }
        },

        getCurrentLocation() {
            if (!navigator.geolocation) {
                Swal.fire('Hata', 'Tarayıcınız veya cihazınız konum servisini desteklemiyor.', 'error');
                return;
            }
            
            Swal.fire({ title: 'Konum Bulunuyor...', text: 'Lütfen cihazınızdan konum erişimine izin verin.', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
            
            navigator.geolocation.getCurrentPosition(
                (position) => {
                    const lat = position.coords.latitude;
                    const lng = position.coords.longitude;
                    this.formData.lat = lat.toFixed(8);
                    this.formData.lng = lng.toFixed(8);
                    
                    if (this.mapInstance && this.markerInstance) {
                        const newLatLng = new L.LatLng(lat, lng);
                        this.mapInstance.setView(newLatLng, 19);
                        this.markerInstance.setLatLng(newLatLng);
                    }
                    Swal.close();
                },
                (error) => {
                    let msg = 'Konum alınamadı. Sinyal zayıf olabilir.';
                    if (error.code === 1) msg = 'Konum izni reddedildi. Lütfen cihaz/tarayıcı ayarlarınızdan izin verin.';
                    else if (error.code === 2) msg = 'Konum servisi şu an kullanılamıyor.';
                    else if (error.code === 3) msg = 'Konum isteği zaman aşımına uğradı.';
                    Swal.fire('Hata', msg, 'error');
                },
                { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
            );
        },
        
        logout() {
            localStorage.removeItem('mbs_auth_token');
            localStorage.removeItem('mbs_admin_logged');
            localStorage.removeItem('mbs_admin_user');
            localStorage.removeItem('mbs_admin_role');
            localStorage.removeItem('mbs_admin_name');
            window.location.href = 'login.html';
        },
        
        openImageLightbox(url) {
            if (!url) return;
            Swal.fire({
                imageUrl: '../' + url,
                imageAlt: 'Görsel',
                showCloseButton: true,
                showConfirmButton: false,
                width: 'auto',
                padding: '1em',
                customClass: { popup: 'rounded-xl' }
            });
        },

        // --- MEZARLIK YÖNETİMİ METOTLARI ---
        async loadMezarliklar() {
            this.isMezarliklarLoading = true;
            try {
                const res = await apiService.fetchMezarliklar();
                if (res && res.status === 'success' && Array.isArray(res.data)) {
                    this.mezarliklar = res.data;
                } else if (res && Array.isArray(res)) {
                    this.mezarliklar = res;
                }
            } catch (err) {
                console.error("Mezarlıklar yüklenemedi", err);
            } finally {
                this.isMezarliklarLoading = false;
            }
        },

        // --- KULLANICI YÖNETİMİ METOTLARI ---
        async loadUsers() {
            this.isUsersLoading = true;
            try {
                const res = await apiService.fetchUsers();
                if (res.status === 'success') {
                    this.users = res.data;
                }
            } catch (err) {
                console.error("Kullanıcılar yüklenemedi", err);
            } finally {
                this.isUsersLoading = false;
            }
        },

        openUserModal(mode, user = null) {
            this.userModalMode = mode;
            if (mode === 'edit' && user) {
                this.newUserForm = {
                    id: user.id,
                    username: user.username,
                    password: '',
                    ad_soyad: user.ad_soyad,
                    unvan: user.unvan,
                    telefon: user.telefon || '',
                    eposta: user.eposta || ''
                };
            } else {
                this.newUserForm = { id: null, username: '', password: '', ad_soyad: '', unvan: '', telefon: '', eposta: '' };
            }
            this.isNewUserModalOpen = true;
        },

        async saveUser() {
            if (!this.newUserForm.username || !this.newUserForm.ad_soyad) {
                Swal.fire('Eksik Bilgi', 'Kullanıcı adı ve Ad Soyad alanları zorunludur.', 'warning');
                return;
            }
            try {
                Swal.fire({ title: 'Kaydediliyor...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
                let res;
                if (this.userModalMode === 'edit') {
                    res = await apiService.updateUser(this.newUserForm);
                } else {
                    if (!this.newUserForm.password) {
                        Swal.fire('Eksik Bilgi', 'Yeni kullanıcı için şifre zorunludur.', 'warning');
                        return;
                    }
                    res = await apiService.saveUser(this.newUserForm);
                }
                
                if (res.status === 'success') {
                    Swal.fire('Başarılı', res.message, 'success');
                    this.isNewUserModalOpen = false;
                    this.newUserForm = { id: null, username: '', password: '', ad_soyad: '', unvan: '', telefon: '', eposta: '' };
                    this.loadUsers();
                    
                    if (this.userModalMode === 'edit') {
                        // Kendi profilini güncellediyse headerı ve localStorage'ı da güncelleyelim.
                        if (this.currentUser.username === this.newUserForm.username || this.currentUser.username === localStorage.getItem('mbs_admin_user')) {
                            this.currentUser.ad_soyad = this.newUserForm.ad_soyad;
                            this.currentUser.username = this.newUserForm.username;
                            localStorage.setItem('mbs_admin_name', this.newUserForm.ad_soyad);
                            localStorage.setItem('mbs_admin_user', this.newUserForm.username);
                        }
                    }
                } else {
                    Swal.fire('Hata', res.message, 'error');
                }
            } catch (err) {
                Swal.fire('Hata', 'Kullanıcı işlemi sırasında bir sorun oluştu.', 'error');
            }
        },

        async toggleUserPermission(userId, permType, currentValue) {
            if (!this.isAdmin) return;
            try {
                // Seçili kullanıcıyı tablodan bul
                const user = this.users.find(u => u.id === userId) || this.selectedUserForPermission;
                if (!user) return;
                
                // Mevcut tüm yetkileri payloada doldur (Backend hepsini birden istiyor)
                const payload = { 
                    id: userId,
                    can_edit: user.can_edit,
                    can_delete: user.can_delete,
                    can_add: user.can_add,
                    can_export: user.can_export,
                    can_approve: user.can_approve
                };
                
                // Tıklanan yetkinin değerini tersine çevir
                payload[permType] = currentValue ? 0 : 1;
                
                const res = await apiService.updateUserPermissions(payload);
                if (res.status === 'success') {
                    this.loadUsers();
                } else {
                    Swal.fire('Hata', res.message, 'error');
                }
            } catch (err) {
                Swal.fire('Hata', 'Yetki güncellenirken bir sorun oluştu.', 'error');
            }
        },

        openPermissionModal(user) {
            this.selectedUserForPermission = user;
            this.isPermissionModalOpen = true;
        },

        async removeUser(userId) {
            if (!this.isAdmin) return;
            const confirm = await Swal.fire({
                title: 'Emin misiniz?',
                text: "Bu kullanıcıyı sistemden tamamen silmek üzeresiniz!",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Evet, Sil!',
                cancelButtonText: 'İptal'
            });

            if (confirm.isConfirmed) {
                try {
                    const res = await apiService.deleteUser(userId);
                    if (res.status === 'success') {
                        Swal.fire('Silindi!', res.message, 'success');
                        this.loadUsers();
                    } else {
                        Swal.fire('Hata', res.message, 'error');
                    }
                } catch (err) {
                    Swal.fire('Hata', 'Kullanıcı silinirken bir sorun oluştu.', 'error');
                }
            }
        },

        // --- RAPORLAR METOTLARI ---
        async loadRaporlar() {
            this.raporLoading = true;
            try {
                const res = await fetch('../api/index.php?action=raporlar', {
                    headers: apiService.getHeaders()
                });
                const data = await res.json();
                if (data.status === 'success' && data.data) {
                    this.raporData = data.data;
                    // Grafik maksimum değerlerini hesapla
                    const yillikSayilar = (data.data.yillik_trend || []).map(t => parseInt(t.sayi));
                    this.raporData._maxYillik = Math.max(...yillikSayilar, 1);
                    const aylikSayilar = (data.data.aylik_dagilim || []).map(a => parseInt(a.sayi));
                    this.raporData._maxAylik = Math.max(...aylikSayilar, 1);
                } else {
                    console.error('Rapor yüklenemedi:', data.message);
                }
            } catch (err) {
                console.error('Rapor hatası:', err);
            } finally {
                this.raporLoading = false;
            }
        },

        // --- SİSTEM AYARLARI METOTLARI ---
        async loadSettings() {
            try {
                const res = await apiService.fetchSettings();
                if (res.status === 'success' && res.data) {
                    // Check if res.data is an array (old format) or an object (new format)
                    if (Array.isArray(res.data)) {
                        res.data.forEach(setting => {
                            if (setting.setting_key === 'belediye_adi') this.systemSettings.belediye_adi = setting.setting_value;
                            if (setting.setting_key === 'logo_url') this.systemSettings.logo_url = setting.setting_value;
                            if (setting.setting_key === 'ip_restriction_enabled') this.systemSettings.ip_restriction_enabled = (setting.setting_value === '1');
                            if (setting.setting_key === 'allowed_ips') this.systemSettings.allowed_ips = setting.setting_value;
                            if (setting.setting_key === 'vatansms_api_id') this.systemSettings.vatansms_api_id = setting.setting_value;
                            if (setting.setting_key === 'vatansms_api_key') this.systemSettings.vatansms_api_key = setting.setting_value;
                            if (setting.setting_key === 'vatansms_sender') this.systemSettings.vatansms_sender = setting.setting_value;
                        });
                    } else {
                        // Object format
                        if (res.data.belediye_adi !== undefined) this.systemSettings.belediye_adi = res.data.belediye_adi;
                        if (res.data.logo_url !== undefined) this.systemSettings.logo_url = res.data.logo_url;
                        if (res.data.ip_restriction_enabled !== undefined) this.systemSettings.ip_restriction_enabled = (res.data.ip_restriction_enabled === '1' || res.data.ip_restriction_enabled === 1 || res.data.ip_restriction_enabled === true);
                        if (res.data.allowed_ips !== undefined) this.systemSettings.allowed_ips = res.data.allowed_ips;
                        if (res.data.vatansms_api_id !== undefined) this.systemSettings.vatansms_api_id = res.data.vatansms_api_id;
                        if (res.data.vatansms_api_key !== undefined) this.systemSettings.vatansms_api_key = res.data.vatansms_api_key;
                        if (res.data.vatansms_sender !== undefined) this.systemSettings.vatansms_sender = res.data.vatansms_sender;
                        if (res.data.birim_sorumlusu_ad !== undefined) this.systemSettings.birim_sorumlusu_ad = res.data.birim_sorumlusu_ad;
                        if (res.data.birim_sorumlusu_unvan !== undefined) this.systemSettings.birim_sorumlusu_unvan = res.data.birim_sorumlusu_unvan;
                        if (res.data.mezarlik_sorumlusu_ad !== undefined) this.systemSettings.mezarlik_sorumlusu_ad = res.data.mezarlik_sorumlusu_ad;
                        if (res.data.mezarlik_sorumlusu_unvan !== undefined) this.systemSettings.mezarlik_sorumlusu_unvan = res.data.mezarlik_sorumlusu_unvan;
                        if (res.data.mudurluk_adi !== undefined) this.systemSettings.mudurluk_adi = res.data.mudurluk_adi;
                        
                        if (res.data.smtp_host !== undefined) this.systemSettings.smtp_host = res.data.smtp_host;
                        if (res.data.smtp_port !== undefined) this.systemSettings.smtp_port = res.data.smtp_port;
                        if (res.data.smtp_user !== undefined) this.systemSettings.smtp_user = res.data.smtp_user;
                        if (res.data.smtp_pass !== undefined) this.systemSettings.smtp_pass = res.data.smtp_pass;
                        if (res.data.smtp_secure !== undefined) this.systemSettings.smtp_secure = res.data.smtp_secure;
                        
                        try {
                            if (res.data.kiosk_ads) {
                                this.kioskAds = JSON.parse(res.data.kiosk_ads);
                                if (!Array.isArray(this.kioskAds)) this.kioskAds = [];
                            }
                        } catch(e) { this.kioskAds = []; }
                    }
                }
            } catch (err) {
                console.error("Ayarlar yüklenemedi", err);
            }
        },

        async saveSettings() {
            try {
                Swal.fire({ title: 'Kaydediliyor...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
                const payload = {
                    belediye_adi: this.systemSettings.belediye_adi,
                    logo_url: this.systemSettings.logo_url,
                    ip_restriction_enabled: this.systemSettings.ip_restriction_enabled ? '1' : '0',
                    allowed_ips: this.systemSettings.allowed_ips,
                    vatansms_api_id: this.systemSettings.vatansms_api_id,
                    vatansms_api_key: this.systemSettings.vatansms_api_key,
                    vatansms_sender: this.systemSettings.vatansms_sender,
                    kiosk_ads: JSON.stringify(this.kioskAds),
                    birim_sorumlusu_ad: this.systemSettings.birim_sorumlusu_ad,
                    birim_sorumlusu_unvan: this.systemSettings.birim_sorumlusu_unvan,
                    mezarlik_sorumlusu_ad: this.systemSettings.mezarlik_sorumlusu_ad,
                    mezarlik_sorumlusu_unvan: this.systemSettings.mezarlik_sorumlusu_unvan,
                    mudurluk_adi: this.systemSettings.mudurluk_adi,
                    smtp_host: this.systemSettings.smtp_host,
                    smtp_port: this.systemSettings.smtp_port,
                    smtp_user: this.systemSettings.smtp_user,
                    smtp_pass: this.systemSettings.smtp_pass,
                    smtp_secure: this.systemSettings.smtp_secure
                };
                const res = await apiService.saveSettings(payload);
                if (res.status === 'success') {
                    Swal.fire('Başarılı', 'Sistem ayarları güncellendi.', 'success');
                } else {
                    Swal.fire('Hata', res.message, 'error');
                }
            } catch (err) {
                Swal.fire('Hata', 'Ayarlar kaydedilirken sorun oluştu.', 'error');
            }
        },

        async uploadLogo(event) {
            let file = event.target.files[0];
            if (!file) return;
            try {
                Swal.fire({ title: 'Yükleniyor...', text: 'Lütfen bekleyin...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
                const res = await apiService.uploadLogo(file);
                if (res.status === 'success') {
                    this.systemSettings.logo_url = res.url;
                    Swal.fire({ icon: 'success', title: 'Başarılı!', text: 'Logo yüklendi. Kalıcı olması için "Değişiklikleri Kaydet" butonuna basmayı unutmayın.', timer: 2000, showConfirmButton: false });
                } else {
                    Swal.fire('Hata', res.message, 'error');
                }
            } catch (err) {
                Swal.fire('Hata', 'Logo yüklenirken bir sorun oluştu.', 'error');
            }
            event.target.value = ''; // Reset input
        },

        async uploadKioskAd(event) {
            let files = event.target.files;
            if (!files || files.length === 0) return;
            try {
                Swal.fire({ title: 'Yükleniyor...', text: 'Lütfen bekleyin...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
                let successCount = 0;
                for(let i = 0; i < files.length; i++) {
                    const res = await apiService.uploadKioskAd(files[i]);
                    if (res.status === 'success') {
                        this.kioskAds.push(res.url);
                        successCount++;
                    }
                }
                Swal.fire({ icon: 'success', title: 'Başarılı!', text: `${successCount} görsel yüklendi. Kalıcı olması için "Değişiklikleri Kaydet" butonuna basmayı unutmayın.`, timer: 2000, showConfirmButton: false });
            } catch (err) {
                Swal.fire('Hata', 'Kiosk görseli yüklenirken bir sorun oluştu.', 'error');
            }
            event.target.value = ''; // Reset input
        },

        removeKioskAd(index) {
            this.kioskAds.splice(index, 1);
            // Uyarı verelim
            Swal.fire({
                icon: 'info',
                title: 'Görsel Silindi',
                text: 'Kalıcı olması için "Değişiklikleri Kaydet" butonuna basmayı unutmayın.',
                timer: 2000,
                showConfirmButton: false
            });
        }
    }));
});
