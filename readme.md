<img src=".wordpress-org/icon-256x256.png" alt="Coywolf PDF Viewer logo" width="128" />

# Coywolf PDF Viewer

Embed and view PDFs on posts and pages with a fast, fully self-hosted viewer. Manage your PDFs in one place, embed them with the Coywolf PDF block, and keep every byte served from your own site.

- **Version:** 1.0.2
- **Requires WordPress:** 6.3+
- **Requires PHP:** 7.4+
- **License:** GPL-2.0-or-later

## Description

Coywolf PDF Viewer adds a PDF library to wp-admin and a **Coywolf PDF** block to the editor:

- **All PDFs** — a standard WordPress table of every PDF with search, filtering, sorting, bulk actions, and pagination. The Posts and Pages columns show where each PDF is embedded and link straight to those filtered post lists.
- **Add PDF** — upload a PDF to the Media Library (or pick an existing one), or serve one from an external URL. Give it a name, a caption, and per-PDF viewer options.
- **Settings** — site-wide defaults for the viewer: height (fit one page automatically — the default — or a fixed pixel height), light/dark/system color scheme, accent color, default zoom, and which toolbar features (Download, Print, Full screen, Sidebar, Search, Zoom) are available.
- **Documentation** — this readme, rendered inside wp-admin.

In the editor, the block opens a picker modal listing all PDFs — type to filter, click to embed — or add a brand-new PDF without leaving the post. Every viewer option can be overridden per block, per PDF, or site-wide (block → PDF → Settings).

Deleting a PDF from the All PDFs table also removes its block from every post and page that embeds it, so nothing is left rendering an empty viewer.

### Built for performance

- The viewer engine ([EmbedPDF](https://www.embedpdf.com/), bundled locally) loads **only on pages that contain the block** — zero scripts anywhere else.
- Embeds are **lazy-loaded**: the viewer module and the PDF download only when the embed scrolls near the screen.
- Optional **click-to-load** mode shows a lightweight, page-shaped preview card — with a poster image behind the Load button, like a video facade — and loads the viewer only on demand, with no layout shift. Choose a poster per PDF, or let Media Library PDFs use their generated first-page preview automatically.
- The viewer renders in a shadow DOM, so theme CSS can't break it (and vice versa).

### Privacy-first

Everything is served from your site: the viewer script, the PDF rendering engine, and (for Media Library PDFs) the documents themselves. The plugin makes **no third-party requests**, sets no cookies, and collects no data. The viewer's CDN conveniences (web fonts, stamp libraries, font fallbacks) are explicitly disabled.

### Fits one page at a time

By default each embed sizes itself to the PDF's page: the viewer height matches the page shape at the current width (toolbar included), so visitors see exactly one full page — not a page and a half — and scroll through page by page. The fit is rotation-aware, re-fits when pages in the document have different sizes, adapts on window resize, and the box is pre-sized before the viewer loads so the layout doesn't shift. Prefer the old behavior? Switch Height to a fixed pixel value in Settings, per PDF, or per block.

### Nice extras

- Optional schema.org `DigitalDocument` structured data for each embed.
- A plain download link inside every embed for no-JavaScript visitors, feed readers, and crawlers.
- The viewer UI follows your site language (10 locales built in).
- Dark mode that can follow the visitor's system preference.

## Installation

1. Upload and activate the plugin.
2. Go to **PDFs → Add PDF** and add your first document.
3. Add the **Coywolf PDF** block to any post or page and pick the PDF.

## Frequently Asked Questions

### Why doesn't my external-URL PDF load?

The viewer fetches PDFs with JavaScript, so PDFs served from another domain must allow cross-origin requests (CORS). PDFs on your own site always work. If the external host doesn't send `Access-Control-Allow-Origin`, upload the file to the Media Library instead.

### Does embedding a PDF slow my pages down?

No. Pages without the block load no plugin assets at all. Pages with the block load one small script; the viewer engine and the PDF itself only download when the embed approaches the viewport (or on click, in click-to-load mode).

### Can I disable downloading or printing?

Yes — globally in Settings, per PDF, or per block. Note these controls hide/disable the viewer UI; like any web embed, they can't stop a determined visitor from fetching a file the browser was given.

### Some characters in my PDF show as squares. Why?

To guarantee zero third-party requests, the viewer's remote font fallback (for PDFs that don't embed their own fonts, common with CJK/Arabic documents) is disabled. Most PDFs embed their fonts and render perfectly.

### Which browsers are supported?

All modern browsers (Chrome, Edge, Firefox, Safari). The viewer uses WebAssembly; visitors with JavaScript disabled get a direct link to the PDF instead.

## Privacy & third-party services

This plugin phones no home. It bundles the MIT-licensed [EmbedPDF](https://github.com/embedpdf/embed-pdf-viewer) viewer (which uses Google's BSD-licensed PDFium engine compiled to WebAssembly) and serves it from your own site. If you embed a PDF from an external URL, your visitors' browsers request that file from the external host when the viewer loads.

<!-- wporg-strip:start -->
## Updates

This plugin updates itself from GitHub releases at [coywolf-llc/coywolf-pdf-viewer](https://github.com/coywolf-llc/coywolf-pdf-viewer). When a new release is published, the update appears on **Dashboard → Updates** like any other plugin.
<!-- wporg-strip:end -->

## Changelog

### 1.0.2
- Click to load: poster facade and shift-free placeholder sizing (#3).

### 1.0.1
- Viewer: auto-fit height to one PDF page (new default) (#2).

### 1.0.0
- Initial release: PDF library (All PDFs, Add PDF, Settings, Documentation), the Coywolf PDF block with picker and in-editor add, per-block/per-PDF/site-wide option inheritance, lazy-loaded self-hosted EmbedPDF viewer, click-to-load mode, usage tracking with filtered post lists, block scrubbing on delete, and schema.org structured data.
