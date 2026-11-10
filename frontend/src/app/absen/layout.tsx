/**
 * The public /absen pages run on whatever phone or class iPad a student has.
 * When a page breaks there, nobody can open DevTools - so this inline script,
 * which runs while the HTML is still being parsed (before any app chunk),
 * writes uncaught errors onto the page itself. The student or teacher can
 * screenshot the message instead of reporting "layarnya putih".
 *
 * Inline script per Next's preventing-flash-before-hydration guide: a server
 * render emits it as text/javascript, a client render as text/plain (ignored).
 */

const CATCHER = `
(function () {
  var shown = 0;
  function report(msg) {
    if (shown >= 3 || /ResizeObserver loop/.test(String(msg))) return;
    shown++;
    var box = document.getElementById('absen-js-error');
    if (!box) return;
    box.style.display = 'block';
    var line = document.createElement('div');
    line.textContent = String(msg).slice(0, 300);
    box.appendChild(line);
  }
  window.addEventListener('error', function (e) {
    var where = e.filename ? ' (' + e.filename.split('/').pop() + ':' + e.lineno + ')' : '';
    report((e.message || 'Gagal memuat skrip') + where);
  }, true);
  window.addEventListener('unhandledrejection', function (e) {
    var r = e.reason;
    report('Promise: ' + (r && r.message ? r.message : r));
  });
})();
`;

export default function AbsenLayout({ children }: { children: React.ReactNode }) {
  return (
    <>
      <script
        type={typeof window === "undefined" ? "text/javascript" : "text/plain"}
        suppressHydrationWarning
        dangerouslySetInnerHTML={{ __html: CATCHER }}
      />
      {children}
      <div
        id="absen-js-error"
        suppressHydrationWarning
        style={{ display: "none" }}
        className="fixed inset-x-3 bottom-3 z-50 rounded-lg border border-bad/30 bg-card p-3 text-left text-xs text-bad shadow-lg"
      >
        <strong className="mb-1 block">Terjadi error di perangkat ini — kirim screenshot ke admin:</strong>
      </div>
    </>
  );
}
