// Nexus Tune Portal - Mobile Drawer & Interaction Controller
document.addEventListener('DOMContentLoaded', () => {
  const toggleBtn = document.getElementById('mobileToggleBtn');
  const closeBtn = document.getElementById('sidebarCloseBtn');
  const sidebar = document.getElementById('appSidebar');
  const backdrop = document.getElementById('sidebarBackdrop');

  function openSidebar() {
    if (sidebar) sidebar.classList.add('open');
    if (backdrop) backdrop.classList.add('active');
    document.body.style.overflow = 'hidden';
  }

  function closeSidebar() {
    if (sidebar) sidebar.classList.remove('open');
    if (backdrop) backdrop.classList.remove('active');
    document.body.style.overflow = '';
  }

  // Toggle button
  if (toggleBtn) {
    toggleBtn.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      if (sidebar && sidebar.classList.contains('open')) {
        closeSidebar();
      } else {
        openSidebar();
      }
    });
  }

  // Explicit close button
  if (closeBtn) {
    closeBtn.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      closeSidebar();
    });
  }

  // Backdrop click closes sidebar
  if (backdrop) {
    backdrop.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      closeSidebar();
    });
  }

  // STOP PROPAGATION inside sidebar so clicking any item/scrolling NEVER closes the menu
  if (sidebar) {
    sidebar.addEventListener('click', (e) => {
      e.stopPropagation();
    });
    sidebar.addEventListener('touchstart', (e) => {
      e.stopPropagation();
    }, { passive: true });
  }

  // Close sidebar on ESC key
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && sidebar && sidebar.classList.contains('open')) {
      closeSidebar();
    }
  });

  // Auto ISRC Generator Helper
  window.generateISRC = function(targetInputId) {
    const input = document.getElementById(targetInputId);
    if (!input) return;
    const year = new Date().getFullYear().toString().slice(-2);
    const randDigits = Math.floor(10000 + Math.random() * 90000);
    input.value = `QZNT1${year}${randDigits}`;
  };

  // Auto UPC Generator Helper
  window.generateUPC = function(targetInputId) {
    const input = document.getElementById(targetInputId);
    if (!input) return;
    const rand = Math.floor(100000000000 + Math.random() * 900000000000);
    input.value = rand.toString();
  };

  // Cover Art Image Previewer
  const imageInput = document.getElementById('coverArtInput');
  const imagePreview = document.getElementById('coverArtPreview');
  if (imageInput && imagePreview) {
    imageInput.addEventListener('change', (e) => {
      const file = e.target.files[0];
      if (file) {
        const reader = new FileReader();
        reader.onload = (re) => {
          imagePreview.src = re.target.result;
          imagePreview.style.display = 'block';
        };
        reader.readAsDataURL(file);
      }
    });
  }
});
