        tailwind.config = {
            theme: {
                extend: {
                    colors: { brand: { navy: '#1e3a8a', light: '#eff6ff' } }
                }
            }
        }
        [x-cloak] { display: none !important; }
        body { -webkit-tap-highlight-color: transparent; }



                



                    




                Temizle

        function sayimApp() {
            return {
                isAuthenticated: !!localStorage.getItem('mbs_auth_token'),
                isLoading: false,
                isUploading: false,
                isSaving: false,
                loginData: { username: '', password: '' },
                mezarliklar: [],
                map: null,
                marker: null,
                
                draft: {
                    mezarlik_id: '',
                    ad: '', soyad: '', ada_no: '', parsel_no: '', olum_tarihi: '',
                    lat: '', lng: '', gorsel_url: '',
                    katli_mezar: 'yok', aciklama: ''
                },

                async init() {
                    // Watch for draft changes to save to localStorage
                        localStorage.setItem('sayim_draft', JSON.stringify(val));
                    }, { deep: true });

                    if (this.isAuthenticated) {
                        await this.loadApp();
                    }
                },

                async login() {
                    this.isLoading = true;
                    try {
                        const formData = new URLSearchParams();
                        formData.append('username', this.loginData.username);
                        formData.append('password', this.loginData.password);
                        const res = await fetch('../api/index.php?action=login', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: formData.toString()
                        });
                        const result = await res.json();
                        
                        if (result.status === 'success') {
                            localStorage.setItem('mbs_auth_token', result.token);
                            localStorage.setItem('mbs_admin_user', this.loginData.username);
                            localStorage.setItem('mbs_admin_name', result.user.ad_soyad);
                            localStorage.setItem('mbs_admin_role', result.user.role);
                            this.isAuthenticated = true;
                            await this.loadApp();
                        } else {
                            Swal.fire('Hata', result.message || 'Giriş başarısız.', 'error');
                        }
                    } catch(e) {
                        Swal.fire('Hata', 'Bağlantı hatası.', 'error');
                    }
                    this.isLoading = false;
                },

                logout() {
                    localStorage.removeItem('mbs_auth_token');
                    localStorage.removeItem('mbs_admin_user');
                    localStorage.removeItem('mbs_admin_name');
                    localStorage.removeItem('mbs_admin_role');
                    this.isAuthenticated = false;
                },

                async loadApp() {
                    // Load draft
                    const saved = localStorage.getItem('sayim_draft');
                    if (saved) {
                        try { this.draft = { ...this.draft, ...JSON.parse(saved) }; } catch(e) {}
                    }
                    
                    // Fetch mezarliklar
                    const res = await fetch(`${apiService.baseUrl}?action=mezarliklar`, { headers: apiService.getHeaders() });
                    const data = await res.json();
                    this.mezarliklar = data.data || [];

                    // Init map next tick
                },

                initMap() {
                    if (this.map) return;
                    this.map = L.map('sayimMap').setView([40.6520, 29.3520], 13);
                    L.tileLayer('https://{s}.google.com/vt/lyrs=y&x={x}&y={y}&z={z}', {
                        maxZoom: 22, subdomains: ['mt0', 'mt1', 'mt2', 'mt3']
                    }).addTo(this.map);

                    if (this.draft.lat && this.draft.lng) {
                        this.setMarker(this.draft.lat, this.draft.lng);
                        this.map.setView([this.draft.lat, this.draft.lng], 19);
                    }

                        this.setMarker(e.latlng.lat, e.latlng.lng);
                    });
                },

                setMarker(lat, lng) {
                    if (this.marker) this.map.removeLayer(this.marker);
                    this.marker = L.marker([lat, lng]).addTo(this.map);
                    this.draft.lat = lat.toFixed(6);
                    this.draft.lng = lng.toFixed(6);
                },

                async getLocation() {
                    if (!navigator.geolocation) {
                        Swal.fire('Hata', 'Cihazınız konum servisini desteklemiyor.', 'error');
                        return;
                    }
                    navigator.geolocation.getCurrentPosition(
                            this.setMarker(pos.coords.latitude, pos.coords.longitude);
                            this.map.setView([pos.coords.latitude, pos.coords.longitude], 20);
                        },
                            let msg = 'Konum alınamadı.';
                            if (err.code === 1) msg = 'Konum izni reddedildi. Lütfen tarayıcı ayarlarından izin verin.';
                            else if (err.code === 2) msg = 'Konum servisi kullanılamıyor (cihazınızda kapalı olabilir veya HTTPS gerekebilir).';
                            else if (err.code === 3) msg = 'Konum alma isteği zaman aşımına uğradı.';
                            
                            Swal.fire({
                                title: 'Konum Hatası',
                                icon: 'warning'
                            });
                        },
                        { enableHighAccuracy: true, timeout: 10000 }
                    );
                },

                async uploadPhoto(e) {
                    const file = e.target.files[0];
                    if (!file) return;
                    this.isUploading = true;
                    try {
                        // Sıkıştırma yapılabilir, ancak hızlıca API'ye atıyoruz
                        const formData = new FormData();
                        formData.append('gorsel', file);
                        const res = await fetch(`${apiService.baseUrl}?action=upload_gorsel`, {
                            method: 'POST',
                            headers: apiService.getHeaders(true), // Uses auth token
                            body: formData
                        });
                        const data = await res.json();
                        if (data.status === 'success') {
                            this.draft.gorsel_url = data.url;
                        } else {
                            throw new Error(data.message);
                        }
                    } catch (error) {
                        Swal.fire('Hata', 'Fotoğraf yüklenemedi: ' + error.message, 'error');
                    }
                    this.isUploading = false;
                },

                clearForm() {
                    if(confirm('Formu temizlemek istediğinize emin misiniz?')) {
                        const mId = this.draft.mezarlik_id; // Keep mezarlik
                        this.draft = {
                            mezarlik_id: mId, ad: '', soyad: '', ada_no: '', parsel_no: '',
                            olum_tarihi: '', lat: '', lng: '', gorsel_url: '',
                            katli_mezar: 'yok', aciklama: ''
                        };
                        if (this.marker) this.map.removeLayer(this.marker);
                    }
                },

                async saveRecord(force = false) {
                    if (!this.draft.mezarlik_id || !this.draft.ad || !this.draft.soyad || !this.draft.lat) {
                        Swal.fire('Uyarı', 'Lütfen yıldızlı (*) zorunlu alanları doldurun.', 'warning');
                        return;
                    }

                    this.isSaving = true;
                    try {
                        const payload = {
                            ...this.draft,
                            aciklama: (this.draft.katli_mezar === 'var' ? 'Katlı Mezar. ' : '') + this.draft.aciklama,
                            ignore_duplicate: force ? 1 : 0
                        };

                        const res = await fetch(`${apiService.baseUrl}?action=defin_ekle`, {
                            method: 'POST',
                            headers: apiService.getHeaders(), // Uses auth token
                            body: JSON.stringify(payload)
                        });
                        
                        if (res.status === 409) {
                            const data = await res.json();
                            if (confirm(data.message)) {
                                return this.saveRecord(true); // Force save
                            }
                            this.isSaving = false;
                            return;
                        }

                        const data = await res.json();
                        if (data.status === 'success') {
                            Swal.fire({
                                title: 'Başarılı!',
                                text: 'Sayım kaydı eklendi.',
                                icon: 'success',
                                timer: 1500,
                                showConfirmButton: false
                            });
                            // Temizle ama Mezarlık, Ada ve Parsel kalsın (seri sayım için)
                            const mId = this.draft.mezarlik_id;
                            const ada = this.draft.ada_no;
                            const parsel = this.draft.parsel_no;
                            this.draft = {
                                mezarlik_id: mId, ad: '', soyad: '', ada_no: ada, parsel_no: parsel,
                                olum_tarihi: '', lat: '', lng: '', gorsel_url: '',
                                katli_mezar: 'yok', aciklama: ''
                            };
                            if (this.marker) this.map.removeLayer(this.marker);
                        } else {
                            throw new Error(data.message);
                        }
                    } catch (error) {
                        Swal.fire('Hata', error.message || 'Kayıt başarısız.', 'error');
                    }
                    this.isSaving = false;
                }
            }
        }
