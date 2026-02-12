/**
 * Custom PDF Viewer Component
 * Provides consistent PDF viewing across different browsers
 */

class CustomPDFViewer {
    constructor(container, options = {}) {
        this.container = container;
        this.options = {
            width: '100%',
            height: '100%',
            showToolbar: false,
            enableZoom: true,
            enableDownload: true,
            enablePrint: true,
            ...options
        };
        
        this.currentPage = 1;
        this.totalPages = 1;
        this.zoomLevel = 1;
        this.pdfDoc = null;
        this.pdfViewer = null;
        this.searchResults = [];
        this.currentResultIndex = 0;
        
        this.init();
    }
    
    init() {
        this.createViewer();
        this.loadPDFJS();
    }
    
    createViewer() {
        // Create viewer structure
        this.container.innerHTML = `
            <div class="custom-pdf-viewer">
                ${this.options.showToolbar ? this.createToolbar() : ''}
                <div class="pdf-content">
                    <div class="pdf-loading">
                        <div class="loading-spinner"></div>
                        <p>Loading PDF document...</p>
                    </div>
                    <div class="pdf-error" style="display: none;">
                        <i class="bi bi-exclamation-triangle"></i>
                        <h5>Error Loading Document</h5>
                        <p>Unable to load the PDF document. Please try again.</p>
                        <button class="btn btn-primary btn-sm retry-btn">Retry</button>
                    </div>
                    <div id="pdf-pages-container" style="display: none;"></div>
                </div>
            </div>
        `;
        
        this.pagesContainer = this.container.querySelector('#pdf-pages-container');
        this.loadingElement = this.container.querySelector('.pdf-loading');
        this.errorElement = this.container.querySelector('.pdf-error');
        
        // Add event listeners
        if (this.options.showToolbar) {
            this.setupToolbarEvents();
        }
        
        // Setup retry button
        const retryBtn = this.container.querySelector('.retry-btn');
        if (retryBtn) {
            retryBtn.addEventListener('click', () => this.loadPDF());
        }
    }
    
    createToolbar() {
        return `
            <div class="pdf-toolbar">
                <div class="toolbar-left">
                    <span class="page-info">
                        Total Pages: <span class="total-pages">1</span>
                    </span>
                </div>
                <div class="toolbar-center">
                    <div class="search-container">
                        <div class="search-input-wrapper">
                            <input type="text" class="search-input" placeholder="Search in document..." />
                        </div>
                        <span class="search-results-count" style="display: none;">
                            <button class="btn btn-sm btn-outline-secondary prev-result-btn" title="Previous Result">
                                <i class="bi bi-chevron-up"></i>
                            </button>
                            <span class="results-number">0</span> of <span class="total-results">0</span>
                            <button class="btn btn-sm btn-outline-secondary next-result-btn" title="Next Result">
                                <i class="bi bi-chevron-down"></i>
                            </button>
                        </span>
                    </div>
                    <button class="btn btn-sm btn-outline-secondary zoom-out" title="Zoom Out">
                        <i class="bi bi-dash"></i>
                    </button>
                    <span class="zoom-level">100%</span>
                    <button class="btn btn-sm btn-outline-secondary zoom-in" title="Zoom In">
                        <i class="bi bi-plus"></i>
                    </button>
                </div>
                <div class="toolbar-right">
                    <button class="btn btn-sm btn-outline-secondary download-btn" title="Download">
                        <i class="bi bi-download"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-secondary print-btn" title="Print">
                        <i class="bi bi-printer"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-secondary fullscreen-btn" title="Toggle Fullscreen">
                        <i class="bi bi-fullscreen"></i>
                    </button>
                </div>
            </div>
        `;
    }
    
    setupToolbarEvents() {
        const zoomIn = this.container.querySelector('.zoom-in');
        const zoomOut = this.container.querySelector('.zoom-out');
        const downloadBtn = this.container.querySelector('.download-btn');
        const printBtn = this.container.querySelector('.print-btn');
        const fullscreenBtn = this.container.querySelector('.fullscreen-btn');
        const searchInput = this.container.querySelector('.search-input');
        const prevResultBtn = this.container.querySelector('.prev-result-btn');
        const nextResultBtn = this.container.querySelector('.next-result-btn');
        
        if (zoomIn) {
            zoomIn.addEventListener('click', () => this.zoomIn());
        }
        
        if (zoomOut) {
            zoomOut.addEventListener('click', () => this.zoomOut());
        }
        
        if (downloadBtn) {
            downloadBtn.addEventListener('click', () => this.downloadPDF());
        }
        
        if (printBtn) {
            printBtn.addEventListener('click', () => this.printPDF());
        }
        
        if (fullscreenBtn) {
            fullscreenBtn.addEventListener('click', () => this.toggleFullscreen());
            
            // Listen for fullscreen changes to update button icon
            document.addEventListener('fullscreenchange', () => this.updateFullscreenButton());
            document.addEventListener('webkitfullscreenchange', () => this.updateFullscreenButton());
            document.addEventListener('mozfullscreenchange', () => this.updateFullscreenButton());
            document.addEventListener('MSFullscreenChange', () => this.updateFullscreenButton());
        }
        

        
        if (prevResultBtn) {
            prevResultBtn.addEventListener('click', () => this.navigateToPreviousResult());
        }
        
        if (nextResultBtn) {
            nextResultBtn.addEventListener('click', () => this.navigateToNextResult());
        }
        
        if (searchInput) {
            // Real-time search with debouncing
            let searchTimeout;
            searchInput.addEventListener('input', (e) => {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    this.searchDocument();
                }, 300); // 300ms delay for real-time search
            });
            
            searchInput.addEventListener('keypress', (e) => {
                if (e.key === 'Enter') {
                    this.searchDocument();
                }
            });
        }
    }
    
    async loadPDFJS() {
        // Load PDF.js library dynamically
        if (typeof pdfjsLib === 'undefined') {
            const script = document.createElement('script');
            script.src = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js';
            script.onload = () => {
                pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
                this.loadPDF();
            };
            script.onerror = () => {
                this.showError('Failed to load PDF.js library');
            };
            document.head.appendChild(script);
        } else {
            this.loadPDF();
        }
    }
    
    async loadPDF(url = null) {
        if (url) {
            this.pdfUrl = url;
        }
        
        if (!this.pdfUrl) {
            this.showError('No PDF URL provided');
            return;
        }
        
        this.showLoading();
        
        try {
            const loadingTask = pdfjsLib.getDocument(this.pdfUrl);
            this.pdfDoc = await loadingTask.promise;
            
            this.totalPages = this.pdfDoc.numPages;
            this.updatePageInfo();
            
            await this.renderAllPages();
            this.hideLoading();
            
        } catch (error) {
            console.error('Error loading PDF:', error);
            this.showError('Failed to load PDF document');
        }
    }
    
    async renderAllPages() {
        if (!this.pdfDoc) return;
        
        try {
            this.pagesContainer.innerHTML = '';
            
            for (let pageNum = 1; pageNum <= this.totalPages; pageNum++) {
                const page = await this.pdfDoc.getPage(pageNum);
                const viewport = page.getViewport({ scale: this.zoomLevel });
                
                // Create canvas for each page
                const canvas = document.createElement('canvas');
                canvas.width = viewport.width;
                canvas.height = viewport.height;
                canvas.style.display = 'block';
                canvas.style.margin = '10px auto';
                canvas.style.boxShadow = '0 2px 10px rgba(0, 0, 0, 0.1)';
                canvas.style.maxWidth = '100%';
                canvas.style.height = 'auto';
                canvas.dataset.pageNum = pageNum;
                
                // Create page container
                const pageContainer = document.createElement('div');
                pageContainer.className = 'pdf-page';
                pageContainer.appendChild(canvas);
                
                this.pagesContainer.appendChild(pageContainer);
                
                // Render PDF page
                const context = canvas.getContext('2d');
                const renderContext = {
                    canvasContext: context,
                    viewport: viewport
                };
                
                await page.render(renderContext).promise;
            }
            
            this.pagesContainer.style.display = 'block';
            
        } catch (error) {
            console.error('Error rendering pages:', error);
            this.showError('Failed to render PDF pages');
        }
    }
    
    async renderPage(pageNum) {
        if (!this.pdfDoc) return;
        
        try {
            const page = await this.pdfDoc.getPage(pageNum);
            const viewport = page.getViewport({ scale: this.zoomLevel });
            
            // Set canvas dimensions
            this.canvas.width = viewport.width;
            this.canvas.height = viewport.height;
            
            // Render PDF page
            const context = this.canvas.getContext('2d');
            const renderContext = {
                canvasContext: context,
                viewport: viewport
            };
            
            await page.render(renderContext).promise;
            
            this.currentPage = pageNum;
            this.updatePageInfo();
            this.canvas.style.display = 'block';
            
        } catch (error) {
            console.error('Error rendering page:', error);
            this.showError('Failed to render PDF page');
        }
    }
    
    updatePageInfo() {
        const totalPagesEl = this.container.querySelector('.total-pages');
        const zoomLevelEl = this.container.querySelector('.zoom-level');
        
        if (totalPagesEl) totalPagesEl.textContent = this.totalPages;
        if (zoomLevelEl) zoomLevelEl.textContent = Math.round(this.zoomLevel * 100) + '%';
    }
    
    async searchDocument() {
        const searchInput = this.container.querySelector('.search-input');
        const searchTerm = searchInput.value.trim();
        
        if (!searchTerm) {
            this.clearSearch();
            return;
        }
        
        try {
            this.clearSearchHighlights();
            this.searchResults = [];
            this.currentResultIndex = 0;
            
            let totalResults = 0;
            let firstResultPage = null;
            let firstResultElement = null;
            
            for (let pageNum = 1; pageNum <= this.totalPages; pageNum++) {
                const page = await this.pdfDoc.getPage(pageNum);
                const textContent = await page.getTextContent();
                
                // Find exact matches within text items
                const exactMatches = [];
                textContent.items.forEach(item => {
                    const text = item.str;
                    const lowerText = text.toLowerCase();
                    const lowerSearchTerm = searchTerm.toLowerCase();
                    
                    let startIndex = 0;
                    while (true) {
                        const index = lowerText.indexOf(lowerSearchTerm, startIndex);
                        if (index === -1) break;
                        
                        // Create a new result for each exact match
                        exactMatches.push({
                            ...item,
                            matchStart: index,
                            matchEnd: index + searchTerm.length,
                            originalText: text,
                            matchedText: text.substring(index, index + searchTerm.length)
                        });
                        
                        startIndex = index + 1;
                    }
                });
                
                if (exactMatches.length > 0) {
                    await this.highlightSearchResults(pageNum, exactMatches, searchTerm);
                    totalResults += exactMatches.length;
                    
                    // Store all results for navigation
                    exactMatches.forEach(result => {
                        this.searchResults.push({
                            pageNum: pageNum,
                            element: this.pagesContainer.querySelector(`canvas[data-page-num="${pageNum}"]`),
                            result: result,
                            position: result.transform[5] // Y position for sorting within page
                        });
                    });
                    
                    // Track first result for scrolling
                    if (firstResultPage === null) {
                        firstResultPage = pageNum;
                        firstResultElement = this.pagesContainer.querySelector(`canvas[data-page-num="${pageNum}"]`);
                    }
                }
            }
            
            // Sort results chronologically (by page number, then by position on page)
            this.searchResults.sort((a, b) => {
                if (a.pageNum !== b.pageNum) {
                    return a.pageNum - b.pageNum; // Sort by page number first
                }
                return b.position - a.position; // Then by Y position (top to bottom)
            });
            
            // Set first result as current
            if (this.searchResults.length > 0) {
                this.currentResultIndex = 0;
            }
            
            // Update search results count and display
            this.updateSearchResultsCount(totalResults);
            
            // Scroll to first result if found
            if (firstResultElement && totalResults > 0) {
                this.scrollToElement(firstResultElement);
            }
            

            
        } catch (error) {
            console.error('Error searching document:', error);
        }
    }
    
    async highlightSearchResults(pageNum, searchResults, searchTerm) {
        const canvas = this.pagesContainer.querySelector(`canvas[data-page-num="${pageNum}"]`);
        if (!canvas) return;
        
        // Get the original page to calculate proper coordinates
        const page = await this.pdfDoc.getPage(pageNum);
        const viewport = page.getViewport({ scale: this.zoomLevel });
        
        // Create a temporary canvas to overlay highlights
        const overlayCanvas = document.createElement('canvas');
        overlayCanvas.width = canvas.width;
        overlayCanvas.height = canvas.height;
        overlayCanvas.style.position = 'absolute';
        overlayCanvas.style.top = canvas.offsetTop + 'px';
        overlayCanvas.style.left = canvas.offsetLeft + 'px';
        overlayCanvas.style.pointerEvents = 'none';
        overlayCanvas.style.zIndex = '10';
        overlayCanvas.className = 'search-highlight-overlay';
        
        const ctx = overlayCanvas.getContext('2d');
        
        searchResults.forEach((result, index) => {
            const transform = result.transform;
            
            // Calculate the position in the scaled viewport
            const x = transform[4] * this.zoomLevel;
            const y = viewport.height - (transform[5] * this.zoomLevel) - (result.height * this.zoomLevel);
            
            // Calculate the width of the specific matched text
            const totalWidth = result.width * this.zoomLevel;
            const matchStart = result.matchStart || 0;
            const matchEnd = result.matchEnd || result.originalText.length;
            const matchLength = matchEnd - matchStart;
            const totalLength = result.originalText.length;
            
            // Calculate the width of the matched portion
            const matchedWidth = (matchLength / totalLength) * totalWidth;
            const matchedX = x + (matchStart / totalLength) * totalWidth;
            
            // Check if this is the current result
            const isCurrentResult = this.searchResults.find(sr => 
                sr.pageNum === pageNum && 
                sr.result === result
            ) && this.currentResultIndex === this.searchResults.findIndex(sr => 
                sr.pageNum === pageNum && 
                sr.result === result
            );
            
            // Draw highlight rectangle - different color for current result
            ctx.save();
            if (isCurrentResult) {
                // Special highlight for current result
                ctx.globalAlpha = 0.6;
                ctx.fillStyle = '#ff9800'; // Orange for current result
            } else {
                // Normal highlight for other results
                ctx.globalAlpha = 0.4;
                ctx.fillStyle = '#ffeb3b'; // Yellow for other results
            }
            ctx.fillRect(matchedX, y, matchedWidth, result.height * this.zoomLevel);
            ctx.restore();
        });
        
        // Insert overlay after the original canvas
        canvas.parentNode.insertBefore(overlayCanvas, canvas.nextSibling);
    }
    
    clearSearch() {
        const searchInput = this.container.querySelector('.search-input');
        const resultsCount = this.container.querySelector('.search-results-count');
        
        if (searchInput) {
            searchInput.value = '';
            searchInput.placeholder = 'Search in document...';
        }
        if (resultsCount) resultsCount.style.display = 'none';
        
        this.clearSearchHighlights();
        this.searchResults = [];
        this.currentResultIndex = 0;
    }
    
    clearSearchHighlights() {
        // Remove all search highlight overlays
        const overlays = this.pagesContainer.querySelectorAll('.search-highlight-overlay');
        overlays.forEach(overlay => overlay.remove());
    }
    
    updateSearchResultsCount(count) {
        const resultsCount = this.container.querySelector('.search-results-count');
        const resultsNumber = this.container.querySelector('.results-number');
        const totalResults = this.container.querySelector('.total-results');
        
        if (resultsCount && resultsNumber && totalResults) {
            if (count > 0) {
                resultsNumber.textContent = this.currentResultIndex + 1;
                totalResults.textContent = count;
                resultsCount.style.display = 'inline-block';
            } else {
                resultsCount.style.display = 'none';
            }
        }
    }
    
    navigateToPreviousResult() {
        if (this.searchResults.length > 0) {
            this.currentResultIndex = (this.currentResultIndex - 1 + this.searchResults.length) % this.searchResults.length;
            this.updateCurrentResultHighlight();
            this.scrollToElement(this.searchResults[this.currentResultIndex].element);
            this.updateSearchResultsCount(this.searchResults.length);
        }
    }
    
    navigateToNextResult() {
        if (this.searchResults.length > 0) {
            this.currentResultIndex = (this.currentResultIndex + 1) % this.searchResults.length;
            this.updateCurrentResultHighlight();
            this.scrollToElement(this.searchResults[this.currentResultIndex].element);
            this.updateSearchResultsCount(this.searchResults.length);
        }
    }
    
    highlightCurrentResult() {
        // Remove previous current result highlight
        const overlays = this.pagesContainer.querySelectorAll('.search-highlight-overlay');
        overlays.forEach(overlay => {
            overlay.style.border = 'none';
        });
        
        // Add special highlight for current result (different color instead of border)
        if (this.searchResults.length > 0) {
            const currentResult = this.searchResults[this.currentResultIndex];
            const overlay = this.pagesContainer.querySelector(`canvas[data-page-num="${currentResult.pageNum}"]`).nextSibling;
            if (overlay && overlay.className === 'search-highlight-overlay') {
                // Use a different highlight color for current result instead of border
                const ctx = overlay.getContext('2d');
                ctx.globalAlpha = 0.6;
                ctx.fillStyle = '#ff9800';
                // This will be handled in the main highlight method
            }
        }
        
        this.updateSearchResultsCount(this.searchResults.length);
    }
    
    scrollToElement(element) {
        const pdfContent = this.container.querySelector('.pdf-content');
        if (pdfContent && element) {
            const elementRect = element.getBoundingClientRect();
            const containerRect = pdfContent.getBoundingClientRect();
            
            // Calculate scroll position to center the element
            const scrollTop = pdfContent.scrollTop + elementRect.top - containerRect.top - (containerRect.height / 2) + (elementRect.height / 2);
            
            // Smooth scroll to the element
            pdfContent.scrollTo({
                top: scrollTop,
                behavior: 'smooth'
            });
            
            // Add a brief highlight effect to the page
            element.style.boxShadow = '0 0 20px rgba(255, 193, 7, 0.8)';
            setTimeout(() => {
                element.style.boxShadow = '';
            }, 2000);
        }
    }
    
    zoomIn() {
        if (this.zoomLevel < 3) {
            this.zoomLevel *= 1.2;
            this.updatePageInfo();
            this.renderAllPages();
            // Re-apply search highlights after zoom
            this.reapplySearchHighlights();
        }
    }
    
    zoomOut() {
        if (this.zoomLevel > 0.5) {
            this.zoomLevel /= 1.2;
            this.updatePageInfo();
            this.renderAllPages();
            // Re-apply search highlights after zoom
            this.reapplySearchHighlights();
        }
    }
    
    toggleFullscreen() {
        const pdfViewer = this.container.querySelector('.custom-pdf-viewer');
        if (!document.fullscreenElement) {
            // Enter fullscreen
            if (pdfViewer.requestFullscreen) {
                pdfViewer.requestFullscreen();
            } else if (pdfViewer.webkitRequestFullscreen) {
                pdfViewer.webkitRequestFullscreen();
            } else if (pdfViewer.msRequestFullscreen) {
                pdfViewer.msRequestFullscreen();
            }
        } else {
            // Exit fullscreen
            if (document.exitFullscreen) {
                document.exitFullscreen();
            } else if (document.webkitExitFullscreen) {
                document.webkitExitFullscreen();
            } else if (document.msExitFullscreen) {
                document.msExitFullscreen();
            }
        }
    }
    
    updateFullscreenButton() {
        const fullscreenBtn = this.container.querySelector('.fullscreen-btn');
        const icon = fullscreenBtn?.querySelector('i');
        
        if (fullscreenBtn && icon) {
            if (document.fullscreenElement || document.webkitFullscreenElement || 
                document.mozFullScreenElement || document.msFullscreenElement) {
                // In fullscreen - show exit icon
                icon.className = 'bi bi-fullscreen-exit';
                fullscreenBtn.title = 'Exit Fullscreen';
            } else {
                // Not in fullscreen - show enter icon
                icon.className = 'bi bi-fullscreen';
                fullscreenBtn.title = 'Enter Fullscreen';
            }
        }
    }
    
    reapplySearchHighlights() {
        const searchInput = this.container.querySelector('.search-input');
        if (searchInput && searchInput.value.trim()) {
            // Clear existing highlights
            this.clearSearchHighlights();
            
            // Re-apply highlights with current result indication
            this.searchDocument();
        }
    }
    
    updateCurrentResultHighlight() {
        // Clear existing highlights
        this.clearSearchHighlights();
        
        // Group results by page
        const resultsByPage = {};
        this.searchResults.forEach(result => {
            if (!resultsByPage[result.pageNum]) {
                resultsByPage[result.pageNum] = [];
            }
            resultsByPage[result.pageNum].push(result.result);
        });
        
        // Re-apply highlights for each page with current result indication
        Object.keys(resultsByPage).forEach(pageNum => {
            this.highlightSearchResults(parseInt(pageNum), resultsByPage[pageNum], '');
        });
    }
    
    downloadPDF() {
        if (this.pdfUrl) {
            const link = document.createElement('a');
            link.href = this.pdfUrl;
            link.download = this.pdfUrl.split('/').pop() || 'document.pdf';
            link.click();
        }
    }
    
    printPDF() {
        if (this.pdfUrl) {
            const printWindow = window.open(this.pdfUrl, '_blank');
            printWindow.onload = () => {
                printWindow.print();
            };
        }
    }
    
    showLoading() {
        if (this.loadingElement) this.loadingElement.style.display = 'flex';
        if (this.errorElement) this.errorElement.style.display = 'none';
        if (this.canvas) this.canvas.style.display = 'none';
    }
    
    hideLoading() {
        if (this.loadingElement) this.loadingElement.style.display = 'none';
    }
    
    showError(message) {
        if (this.loadingElement) this.loadingElement.style.display = 'none';
        if (this.errorElement) {
            this.errorElement.style.display = 'flex';
            const errorText = this.errorElement.querySelector('p');
            if (errorText) errorText.textContent = message;
        }
        if (this.canvas) this.canvas.style.display = 'none';
    }
    
    // Public methods
    loadDocument(url) {
        this.loadPDF(url);
    }
    
    goToPage(pageNum) {
        if (pageNum >= 1 && pageNum <= this.totalPages) {
            this.renderPage(pageNum);
        }
    }
    
    nextPage() {
        if (this.currentPage < this.totalPages) {
            this.renderPage(this.currentPage + 1);
        }
    }
    
    previousPage() {
        if (this.currentPage > 1) {
            this.renderPage(this.currentPage - 1);
        }
    }
}

// Fallback PDF viewer for browsers without PDF.js support
class FallbackPDFViewer {
    constructor(container, options = {}) {
        this.container = container;
        this.options = options;
        this.init();
    }
    
    init() {
        this.container.innerHTML = `
            <div class="fallback-pdf-viewer">
                <div class="pdf-content">
                    <iframe src="${this.options.url}" 
                            class="pdf-iframe"
                            onload="this.style.display='block'"
                            onerror="this.parentElement.querySelector('.pdf-error').style.display='flex'">
                    </iframe>
                    <div class="pdf-loading">
                        <div class="loading-spinner"></div>
                        <p>Loading PDF document...</p>
                    </div>
                    <div class="pdf-error" style="display: none;">
                        <i class="bi bi-exclamation-triangle"></i>
                        <h5>PDF Not Supported</h5>
                        <p>Your browser doesn't support PDF viewing. Please download the document to view it.</p>
                        <a href="${this.options.url}" class="btn btn-primary btn-sm" download>
                            <i class="bi bi-download"></i> Download PDF
                        </a>
                    </div>
                </div>
            </div>
        `;
    }
}

// Initialize PDF viewer based on browser support
function initPDFViewer(container, url, options = {}) {
    // Check if browser supports PDF.js
    if (typeof pdfjsLib !== 'undefined' || document.createElement('canvas').getContext) {
        return new CustomPDFViewer(container, { ...options, url });
    } else {
        return new FallbackPDFViewer(container, { url });
    }
}

// Export for global use
window.CustomPDFViewer = CustomPDFViewer;
window.FallbackPDFViewer = FallbackPDFViewer;
window.initPDFViewer = initPDFViewer; 