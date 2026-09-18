# Nexus Tune - Global Music Distribution Platform 🎵🚀

Nexus Tune is a next-generation, high-performance music distribution platform empowering over 50,000+ independent artists and record labels to distribute unlimited music worldwide to Spotify, Apple Music, TikTok, YouTube Music, Amazon Music, and 250+ streaming platforms across 200+ countries while keeping 100% of their royalties.

---

## 🌟 Platform Highlights & Architecture

### 1. 🌐 Main Landing & Showcase (exustune.com\)
- **Cyber-Luxe Dark Aesthetic**: High-converting, responsive UI with smooth animations, video hero backgrounds, and zero third-party bloat.
- **Top-Tier Global SEO**: Complete Schema.org JSON-LD structured data (\Organization\, \WebSite\, \Service\, \FAQPage\), Open Graph, Twitter Cards, canonical tags, and XML sitemaps.
- **Self-Hosted Assets**: 100% locally hosted assets under \ssets/\ (\ssets/images/\, \ssets/fonts/\, \ssets/media/\, \ssets/js/\).

### 2. ⚖️ Legal & Compliance Center (\legal.nexustune.com\)
- **15 Canonical Legal Documentation Pages**:
  - Terms & Conditions, Privacy Policy, Distribution Agreement, DMCA Policy, Cookie Policy, Anti-Fraud & Anti-Streaming Fraud, Copyright & Content Guidelines, Refund & Cancellation, Royalty Payout Terms, AML & KYC Policy, Community Guidelines, Data Protection (GDPR), API & Developer Terms, Security Disclosure, Law Enforcement Guidelines.
- Responsive cyber-luxe layout with live search, print stylesheets, breadcrumbs, and schema metadata.

### 3. 🎛️ Artist & Record Label Portal (\portal.nexustune.com\)
- **Artist Workspace**:
  - **Single & Album Distribution**: Upload multi-track releases with WAV/MP3 lossless audio processing, cover art validation (3000x3000px), genre tagging, explicit filters, and scheduled global store release dates.
  - **ISRC & UPC Smart Tools**: Automated checksum generator and barcode metadata validator.
  - **Royalties & Payouts**: Real-time analytics, earnings tracker, and automated withdrawal processing via Bank Wire, PayPal, bKash, and Nagad.
  - **Support & Ticketing**: In-app ticketing system with status tracking.
  - **Strict Email Verification**: Zero unauthorized dashboard access without email activation.
- **Super Admin Management**:
  - **Release Moderation Queue**: Review, approve, reject, or request metadata changes on pending releases.
  - **Artist & User Management**: Manage artists, toggle roles, and manage permissions.
  - **Payout Approvals**: Moderate and process artist royalty payout requests.
  - **Inquiry Management**: Inbox for general website contacts.
  - **SMTP Mail Server Control**: Full SMTP configuration (Host, Port, User, Password, TLS/SSL) with **Live Test Email Dispatcher** and socket diagnostics.

---

## 🛠️ Tech Stack & Requirements

- **Backend**: Pure PHP 8.x (No heavy composer dependencies, native PDO SQLite / MySQL compatible).
- **Database**: SQLite 3 (auto-migrating tables, indexes, and settings) with zero manual database setup required.
- **Frontend**: Vanilla JS (ES Modules) + Motion Runtime + Responsive CSS Grid & Flexbox.
- **Mailer Engine**: Pure PHP Socket Mailer supporting TLS 1.2/1.3 and SSL Direct SMTP with AUTH LOGIN.
- **Server**: Apache with \mod_rewrite\ enabled and \.htaccess\ routing.

---

## 🚀 Quick Start & Deployment

1. **Clone the repository**:
   `ash
   git clone https://github.com/samsusiyam/NexusTune.git
   cd NexusTune
   `

2. **Web Server Setup (Apache / XAMPP / Nginx / cPanel)**:
   - Point your virtual host document root to the project folder.
   - Ensure PHP has the following extensions enabled:
     - \pdo_sqlite     - \openssl     - \curl\ / \sockets     - \ileinfo     - \gd\ (for image upload validation)
   - Ensure write permissions on \portal.nexustune.com/data/\ and \portal.nexustune.com/uploads/\.

3. **Super Admin Setup**:
   - Initial credentials are initialized upon first boot.
   - Please update the Super Admin password immediately upon deployment via **Account Settings**.

---

## 📄 License & Attribution

Copyright &copy; 2026 Nexus Tune Inc. All rights reserved.
