<?php /* Стили страниц заявок — те же, что в BossTool (includes/layout_top.php), 11.09.2026. */ ?>
<style>
  .block-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 10px; }
  .block-btn { width: 100%; display: flex; flex-direction: column; align-items: flex-start; gap: 8px;
    padding: 14px 16px; background: #fff; border: 1px solid var(--border); border-radius: 10px;
    color: var(--text); font-weight: 500; cursor: pointer; text-align: left; }
  .block-btn:hover { border-color: var(--accent); box-shadow: 0 1px 5px rgba(15,118,110,.12); background: #f0faf7; }
  .bar { height: 8px; border-radius: 4px; background: #eaeeec; overflow: hidden; margin-top: 5px; }
  .bar > i { display: block; height: 100%; background: var(--accent); }

  /* --- шапка раздела: заголовок слева, легенда/действия справа --- */
  .sec-head { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 12px; flex-wrap: wrap; }
  .sec-head h2 { margin: 0; }
  .legend { display: flex; gap: 14px; font-size: 12.5px; color: var(--muted); white-space: nowrap; }
  .legend i { display: inline-block; width: 9px; height: 9px; border-radius: 50%; margin-right: 5px; vertical-align: middle; }

  /* --- компактная шапка заявки: всё в одну полосу вместо колонки полей --- */
  .req-head-grid { display: grid; grid-template-columns: 1.1fr 1fr 1.4fr auto; gap: 14px; align-items: start; }
  .req-head-grid label { margin-bottom: 5px; }
  .req-head-save { align-self: end; }
  .sup-chosen { display: flex; align-items: center; gap: 10px; min-height: 40px; }
  .req-head-foot { display: flex; align-items: center; justify-content: space-between; gap: 16px;
                   flex-wrap: wrap; margin-top: 14px; padding-top: 12px; border-top: 1px solid var(--border); }
  .req-head-actions { display: flex; gap: 8px; }
  @media (max-width: 1000px) { .req-head-grid { grid-template-columns: 1fr; } }

  /* --- плотная таблица: главное на экране, строк много --- */
  .table-wrap { overflow-x: auto; border: 1px solid var(--border); border-radius: 10px; }
  table.dense { font-size: 13px; }
  table.dense th, table.dense td { padding: 6px 9px; vertical-align: middle; }
  table.dense thead th { position: sticky; top: 0; background: #f5f7f6; z-index: 1;
    border-bottom: 1px solid var(--border); font-size: 11.5px; text-transform: none;
    font-weight: 500; color: var(--muted); letter-spacing: 0; white-space: nowrap; }
  table.dense tbody tr:hover { background: #f4faf8; }
  table.dense tr.red  { background: #fef4f3; }
  table.dense tr.amber { background: #fffaef; }
  table.dense tr.red:hover, table.dense tr.amber:hover { filter: brightness(.985); }
  /* Артикул — как ссылка-опознаватель строки; наименование — обычным текстом рядом, а не под ним:
     так строка остаётся в одну линию и таблица не разъезжается по высоте. */
  .cell-ref { font-weight: 600; color: var(--accent); white-space: nowrap; }
  .cell-name { color: var(--ink, var(--text)); line-height: 1.35; }
  .line-name { line-height: 1.3; margin-top: 2px; }
  .tiny { font-size: 10.5px; line-height: 1.2; max-width: 96px; overflow: hidden;
          text-overflow: ellipsis; white-space: nowrap; margin-left: auto; }
  .dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 6px; vertical-align: middle; }

  /* --- плашки-итоги над таблицей --- */
  .tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 10px; margin-bottom: 14px; }
  .tile { border: 1px solid var(--border); border-radius: 10px; padding: 10px 14px; background: #fbfcfc; }
  .tile .k { font-size: 11.5px; color: var(--muted); line-height: 1.3; }
  .tile .v { font-size: 22px; font-weight: 700; line-height: 1.15; margin: 2px 0; font-variant-numeric: tabular-nums; }
  .tile .v.warn-v { color: var(--warn); }

  /* --- полоса фильтров над таблицей (по образцу SR Lux) --- */
  .filter-bar { display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap; }
  .filter-bar > div { display: flex; flex-direction: column; }
  .filter-bar label { margin-bottom: 4px; white-space: nowrap; }
  .filter-bar input[type=text], .filter-bar input[type=number], .filter-bar select {
    margin: 0; padding: 7px 10px; font-size: 13.5px; min-width: 130px; }
  .filter-bar .range { flex-direction: column; }
  .filter-bar .range > label { margin-bottom: 4px; }
  .filter-bar .range input { min-width: 78px; width: 78px; display: inline-block; }
  .filter-bar .chk { justify-content: flex-end; }
  .filter-bar .chk label { display: flex; align-items: center; gap: 6px; margin: 0 0 8px; font-size: 13.5px; color: var(--text); }
  .filter-bar .chk input { width: auto; margin: 0; }
  .filter-bar .acts { flex-direction: row; gap: 8px; align-items: center; }
  .tiny-sup { font-size: 12px; line-height: 1.3; }
  td.has-stock { color: var(--accent); font-weight: 600; }
  td.nowrap { white-space: nowrap; }
  .cur-tag { font-size: 10.5px; color: var(--muted); margin-left: 4px; vertical-align: middle; }
  .qty-inp { width: 78px; margin: 0; padding: 4px 7px; text-align: right; font-size: 13px; }
  /* Поле заводской цены: выглядит как текст, пока его не тронули — чтобы таблица не пестрила
     рамками, но было понятно, что значение редактируемое. Изменённое подсвечивается. */
  .price-inp { width: 84px; margin: 0; padding: 4px 7px; text-align: right; font-size: 13px;
               border-color: transparent; background: transparent; }
  .price-inp:hover { border-color: var(--border); background: #fff; }
  .price-inp:focus { border-color: var(--accent); background: #fff; }
  .price-inp.changed { border-color: var(--accent); background: #eefaf6; font-weight: 600; }

  /* --- строка управления над таблицей --- */
  .sugg-controls { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; margin-bottom: 12px; }
  .sugg-controls form { display: flex; align-items: center; gap: 8px; }
  .sugg-controls label { margin: 0; white-space: nowrap; }
  .sugg-controls input[type=number] { width: 70px; margin: 0; padding: 6px 9px; font-size: 13.5px; }
  .sugg-controls #suggFilter { width: 340px; margin: 0; padding: 7px 11px; font-size: 13.5px; }
  .sugg-foot { display: flex; align-items: center; gap: 16px; margin-top: 14px; flex-wrap: wrap; }
  .sugg-foot .muted { flex: 1; min-width: 260px; line-height: 1.45; }

  /* Пояснение под заголовком — заметно, но не кричит на пол-экрана. */
  .note { background: #fffaef; border-left: 3px solid var(--warn); color: #6b4b12;
          padding: 9px 13px; border-radius: 0 8px 8px 0; font-size: 13.5px; line-height: 1.5; margin: 0 0 14px; }
</style>

</style>
