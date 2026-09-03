<?php
// bulkify als App auf dem Handy (PWA).
//
// Kein Play Store, kein Download: Android/Chrome und iOS/Safari koennen eine Web-Seite auf den
// Startbildschirm legen. Dann hat sie ein eigenes Icon, startet ohne Browserleiste und fuehlt sich
// an wie eine App. Dafuer braucht die Seite drei Dinge, und die liefert diese Datei aus:
//   1. das manifest (Name, Icon, Startadresse)  -> public/manifest.webmanifest
//   2. einen Service Worker                     -> public/sw.js
//   3. eine Theme-Farbe fuer die Statusleiste
//
// Gilt fuer alle Oberflaechen: internes Dashboard, Lieferantenportal, Kundenportal, Login.

// Die Zeilen fuer den <head>. Ueberall dieselben - so ist es fuer alle die gleiche App.
function pwa_head(): string {
    return '<link rel="manifest" href="/manifest.webmanifest">'
         . '<meta name="theme-color" content="#10210F">'
         . '<link rel="apple-touch-icon" href="/assets/apple-touch-icon.png">'
         . '<meta name="apple-mobile-web-app-capable" content="yes">'
         . '<meta name="apple-mobile-web-app-title" content="bulkify">'
         . '<meta name="mobile-web-app-capable" content="yes">';
}

// Anmeldung des Service Workers. Gehoert ans Ende der Seite, nicht in den <head> - er soll das
// Laden nicht aufhalten. Ohne HTTPS (also lokal ausser auf localhost) macht der Browser das
// ohnehin nicht; das ist in Ordnung und keine Fehlermeldung wert.
function pwa_script(): string {
    return '<script>if("serviceWorker" in navigator){window.addEventListener("load",function(){'
         . 'navigator.serviceWorker.register("/sw.js").catch(function(){});});}</script>';
}

// Laeuft die Seite gerade als installierte App? Wird genutzt, um den Installations-Hinweis
// wegzulassen, wenn er schon erledigt ist.
function pwa_hinweis_script(): string {
    return '<script>(function(){try{if(matchMedia("(display-mode: standalone)").matches||navigator.standalone){'
         . 'document.querySelectorAll("[data-nur-browser]").forEach(function(e){e.hidden=true;});}}catch(e){}})();</script>';
}
