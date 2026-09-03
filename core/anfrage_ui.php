<?php
// Preisanfrage bei Lieferanten – artikelzentriert. EIN Popup, EINE Route, ueberall einsetzbar
// (Rohstoffseite, Rezeptur, Angebots-Editor). Statt pro Seite einen eigenen Weg zu bauen.
require_once __DIR__ . '/schema.php';

// Status eines Rohstoffs bezogen auf Lieferantenpreise:
//   'preise'    – mindestens ein Lieferantenpreis liegt vor
//   'angefragt' – es gibt eine offene Preisanfrage, aber noch keinen Preis
//   'keine'     – weder noch
function anfrage_status(int $item_id): string {
    if ((int) scalar("SELECT COUNT(*) FROM lieferant_preis WHERE item_id=?", [$item_id]) > 0) return 'preise';
    if ((int) scalar("SELECT COUNT(*) FROM lieferant_anfrage WHERE item_id=? AND status='offen'", [$item_id]) > 0) return 'angefragt';
    return 'keine';
}

// Badge zum Status – „Preise liegen vor" / „angefragt" / „kein Preis".
function anfrage_badge(int $item_id): string {
    switch (anfrage_status($item_id)) {
        case 'preise':    return bx_badge('Preise liegen vor', 'ok');
        case 'angefragt': return bx_badge('angefragt', 'warn');
        default:          return bx_badge('kein Preis', 'err');
    }
}

// An wie viele Lieferanten läuft aktuell eine offene Anfrage für diesen Rohstoff?
function anfrage_offen_count(int $item_id): int {
    return (int) scalar("SELECT COUNT(DISTINCT lieferant_id) FROM lieferant_anfrage WHERE item_id=? AND status='offen'", [$item_id]);
}

// Der Knopf, der das Popup für genau diesen Rohstoff öffnet.
function anfrage_button(int $item_id, string $label = 'Preis anfragen', string $klasse = 'btn btn-ghost btn-sm'): string {
    return '<button type="button" class="' . h($klasse) . '" onclick="bxAnfrageOeffnen(' . (int)$item_id . ',this)">'
         . h($label) . '</button>';
}

// Das Popup + JS EINMAL je Seite ausgeben. $back = wohin nach dem Senden zurück.
// Die Lieferantenliste kommt bewusst als Parameter (jede Seite hat sie schon geladen).
function anfrage_modal(array $lieferanten, string $back): void {
    ?>
<div id="bxAnfrageOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;padding:16px">
  <div role="dialog" aria-modal="true" class="bx-panel" style="max-width:520px;width:100%;max-height:92vh;overflow:auto;margin:0">
    <h2 style="margin-top:0">Preis anfragen</h2>
    <div class="muted" style="font-size:13px;margin-bottom:12px" id="bxAnfrageItem">Bei welchen Lieferanten möchten Sie anfragen?</div>
    <form method="post" action="?p=preis_anfragen">
      <input type="hidden" name="item_id" id="bxAnfrageItemId" value="">
      <input type="hidden" name="back" value="<?= h($back) ?>">
      <div style="max-height:230px;overflow:auto;border:1px solid var(--line);border-radius:8px;padding:8px 12px;margin-bottom:12px">
        <?php if (!$lieferanten): ?>
          <div class="muted">Keine Lieferanten angelegt.</div>
        <?php else: foreach ($lieferanten as $l): ?>
          <label style="display:flex;gap:8px;align-items:center;padding:4px 0">
            <input type="checkbox" name="anf_lieferant[]" value="<?= (int)$l['id'] ?>">
            <span><?= h($l['firma']) ?><?= !empty($l['land']) ? ' <span class="muted" style="font-size:12px">· ' . h($l['land']) . '</span>' : '' ?></span>
          </label>
        <?php endforeach; endif; ?>
      </div>
      <div class="bx-row" style="gap:10px">
        <div class="bx-field" style="margin:0;flex:1"><label>Menge (optional)</label><input type="text" name="anf_menge" placeholder="z. B. 500"></div>
      </div>
      <div class="bx-field"><label>Notiz an den Lieferanten (optional)</label><input type="text" name="anf_notiz" maxlength="500"></div>
      <label style="display:flex;gap:8px;align-items:center;margin-bottom:14px">
        <input type="checkbox" name="anf_coa" value="1" checked> <span>CoA / Spezifikation mit anfragen</span>
      </label>
      <div class="bx-row" style="justify-content:flex-end;gap:10px">
        <button type="button" class="btn btn-ghost" onclick="bxAnfrageZu()">Abbrechen</button>
        <button type="submit" class="btn btn-primary">Anfrage senden</button>
      </div>
    </form>
  </div>
</div>
<script>
function bxAnfrageZu(){ document.getElementById('bxAnfrageOverlay').style.display='none'; }
function bxAnfrageOeffnen(itemId, btn){
  document.getElementById('bxAnfrageItemId').value = itemId;
  var name = btn && btn.getAttribute('data-name');
  document.getElementById('bxAnfrageItem').textContent = name
    ? ('Preis für „' + name + '" – bei welchen Lieferanten anfragen?')
    : 'Bei welchen Lieferanten möchten Sie anfragen?';
  document.getElementById('bxAnfrageOverlay').style.display='flex';
}
document.addEventListener('keydown', function(e){ if (e.key === 'Escape') bxAnfrageZu(); });
document.getElementById('bxAnfrageOverlay').addEventListener('click', function(e){ if (e.target === this) bxAnfrageZu(); });
</script>
    <?php
}
