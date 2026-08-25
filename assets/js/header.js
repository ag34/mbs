/**
 * Çiftlikköy Belediyesi Mezarlık Bilgi Sistemi (MBS)
 * Ortak Kurumsal Header (Başlık) Bileşeni
 */

function initMbsHeader(options = {}) {
    const pageType = options.pageType || 'public'; // 'public', 'detail', 'admin'
    const subtitle = options.subtitle || (pageType === 'detail' ? 'Vefat Kaydı Detayı' : (pageType === 'admin' ? 'Yönetim Paneli' : 'Mezarlık Bilgi Sistemi'));
    
    let logoPath = 'https://www.yalovaciftlikkoy.bel.tr/upload/temelbilgiler/1717139886.svg';
    let homeUrl = 'index.html';
    if (window.location.pathname.includes('/admin/')) {
        homeUrl = '../index.html';
    }

    let rightActionsHtml = '';
    
    if (pageType === 'detail') {
        rightActionsHtml = `
            <div class="header-right-actions">
                <div id="adminActionButtons" style="display: none; gap: 0.5rem; align-items: center;"></div>
                <a href="${homeUrl}" class="btn-header-action">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12" y1="19" x2="5" y2="12"/></svg>
                    Arama Sayfasına Dön
                </a>
            </div>
        `;
    } else if (pageType === 'public') {
        rightActionsHtml = '';
    }

    const headerHtml = `
        <header class="mbs-header">
            <div class="mbs-header-container">
                <a href="${homeUrl}" class="mbs-brand">
                    <img src="${logoPath}" alt="Çiftlikköy Belediyesi Amblemi" class="mbs-logo">
                    <div class="mbs-brand-text">
                        <h1 class="mbs-title">T.C. ÇİFTLİKKÖY BELEDİYESİ</h1>
                        <p class="mbs-subtitle">${subtitle}</p>
                    </div>
                </a>
                ${rightActionsHtml}
            </div>
        </header>
    `;

    const existingHeader = document.querySelector('.navbar') || document.querySelector('.page-header') || document.getElementById('appHeader');
    if (existingHeader) {
        existingHeader.outerHTML = headerHtml;
    }
}
