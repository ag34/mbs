// admin/js/api.js

const apiService = {
    baseUrl: '../api/index.php',
    
    getHeaders(isFileUpload = false) {
        const headers = {
            'X-MBS-User': encodeURIComponent(localStorage.getItem('mbs_admin_user') || 'admin'),
            'X-MBS-Name': encodeURIComponent(localStorage.getItem('mbs_admin_name') || 'Yetkili'),
            'X-MBS-Role': encodeURIComponent(localStorage.getItem('mbs_admin_role') || 'superadmin'),
            'X-MBS-Token': localStorage.getItem('mbs_auth_token') || ''
        };
        if (!isFileUpload) {
            headers['Content-Type'] = 'application/json';
        }
        return headers;
    },

    async fetchMezarliklar() {
        const url = `${this.baseUrl}?action=mezarliklar&_t=${Date.now()}`;
        try {
            const res = await fetch(url, { headers: this.getHeaders() });
            if (res.status === 401) { window.location.href = 'login.html'; return; }
            return await res.json();
        } catch (error) {
            console.error("fetchMezarliklar error:", error);
            return { status: 'error', data: [] };
        }
    },

    async fetchMeftalar(query = '', mezarlikId = 0, page = 1, limit = 100, onayDurumu = '') {
        let url = `${this.baseUrl}?action=vatandas_sorgu&admin_key=1&q=${encodeURIComponent(query)}&mezarlik_id=${mezarlikId}&page=${page}&limit=${limit}`;
        if (onayDurumu) url += `&onay_durumu=${encodeURIComponent(onayDurumu)}`;
        try {
            const res = await fetch(url, { headers: this.getHeaders() });
            if (res.status === 401) { window.location.href = 'login.html'; return; }
            return await res.json();
        } catch (error) {
            console.error("fetchMeftalar Error:", error);
            throw error;
        }
    },

    async saveMefta(payload) {
        const action = payload.id ? 'defin_guncelle' : 'defin_ekle';
        try {
            const res = await fetch(`${this.baseUrl}?action=${action}`, {
                method: 'POST',
                headers: this.getHeaders(),
                body: JSON.stringify(payload)
            });
            if (res.status === 401) { window.location.href = 'login.html'; return; }
            return await res.json();
        } catch (error) {
            console.error("saveMefta Error:", error);
            throw error;
        }
    },

    async deleteMefta(id) {
        try {
            const res = await fetch(`${this.baseUrl}?action=defin_sil`, {
                method: 'POST',
                headers: this.getHeaders(),
                body: JSON.stringify({ id })
            });
            if (res.status === 401) { window.location.href = 'login.html'; return; }
            return await res.json();
        } catch (error) {
            console.error("deleteMefta Error:", error);
            throw error;
        }
    },

    async uploadImage(file) {
        const formData = new FormData();
        formData.append('gorsel', file);
        try {
            const res = await fetch(`${this.baseUrl}?action=upload_gorsel`, {
                method: 'POST',
                headers: this.getHeaders(true),
                body: formData
            });
            return await res.json();
        } catch (error) {
            console.error("uploadImage Error:", error);
            throw error;
        }
    },

    async fetchUsers() {
        try {
            const res = await fetch(`${this.baseUrl}?action=kullanicilar`, { headers: this.getHeaders() });
            if (res.status === 401) { window.location.href = 'login.html'; return; }
            return await res.json();
        } catch (error) {
            console.error("fetchUsers Error:", error);
            throw error;
        }
    },

    async saveUser(payload) {
        try {
            const res = await fetch(`${this.baseUrl}?action=kullanici_ekle`, {
                method: 'POST',
                headers: this.getHeaders(),
                body: JSON.stringify(payload)
            });
            if (res.status === 401) { window.location.href = 'login.html'; return; }
            return await res.json();
        } catch (error) {
            console.error("saveUser Error:", error);
            throw error;
        }
    },

    async updateUser(payload) {
        try {
            const res = await fetch(`${this.baseUrl}?action=kullanici_duzenle`, {
                method: 'POST',
                headers: this.getHeaders(),
                body: JSON.stringify(payload)
            });
            if (res.status === 401) { window.location.href = 'login.html'; return; }
            return await res.json();
        } catch (error) {
            console.error("updateUser Error:", error);
            throw error;
        }
    },

    async updateUserPermissions(payload) {
        try {
            const res = await fetch(`${this.baseUrl}?action=kullanici_yetki_guncelle`, {
                method: 'POST',
                headers: this.getHeaders(),
                body: JSON.stringify(payload)
            });
            if (res.status === 401) { window.location.href = 'login.html'; return; }
            return await res.json();
        } catch (error) {
            console.error("updateUserPermissions Error:", error);
            throw error;
        }
    },

    async deleteUser(id) {
        try {
            const res = await fetch(`${this.baseUrl}?action=kullanici_sil`, {
                method: 'POST',
                headers: this.getHeaders(),
                body: JSON.stringify({ id })
            });
            if (res.status === 401) { window.location.href = 'login.html'; return; }
            return await res.json();
        } catch (error) {
            console.error("deleteUser Error:", error);
            throw error;
        }
    },

    async deleteMultipleMefta(ids) {
        try {
            const res = await fetch(`${this.baseUrl}?action=defin_toplu_sil`, {
                method: 'POST',
                headers: this.getHeaders(),
                body: JSON.stringify({ ids: ids.join(',') })
            });
            if (res.status === 401) { window.location.href = 'login.html'; return; }
            return await res.json();
        } catch (error) {
            console.error("deleteMultipleMefta Error:", error);
            throw error;
        }
    },

    async fetchSettings() {
        try {
            const res = await fetch(`${this.baseUrl}?action=settings`, { headers: this.getHeaders() });
            if (res.status === 401) { window.location.href = 'login.html'; return; }
            return await res.json();
        } catch (error) {
            console.error("fetchSettings Error:", error);
            throw error;
        }
    },

    async saveSettings(payload) {
        try {
            const res = await fetch(`${this.baseUrl}?action=settings_save`, {
                method: 'POST',
                headers: this.getHeaders(),
                body: JSON.stringify(payload)
            });
            if (res.status === 401) { window.location.href = 'login.html'; return; }
            return await res.json();
        } catch (error) {
            console.error("saveSettings Error:", error);
            throw error;
        }
    },

    async uploadLogo(file) {
        try {
            const formData = new FormData();
            formData.append('gorsel', file);
            
            let headers = this.getHeaders();
            delete headers['Content-Type']; // Let browser set multipart/form-data boundary
            
            const res = await fetch(`${this.baseUrl}?action=upload_logo`, {
                method: 'POST',
                headers: headers,
                body: formData
            });
            if (res.status === 401) { window.location.href = 'login.html'; return; }
            return await res.json();
        } catch (error) {
            console.error("uploadLogo Error:", error);
            throw error;
        }
    },

    async uploadKioskAd(file) {
        try {
            const formData = new FormData();
            formData.append('gorsel', file);
            
            let headers = this.getHeaders();
            delete headers['Content-Type']; 
            
            const res = await fetch(`${this.baseUrl}?action=kiosk_upload_ad`, {
                method: 'POST',
                headers: headers,
                body: formData
            });
            if (res.status === 401) { window.location.href = 'login.html'; return; }
            return await res.json();
        } catch (error) {
            console.error("uploadKioskAd Error:", error);
            throw error;
        }
    }
};
