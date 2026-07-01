{{-- POC Propostas — folha de estilo base (tokens de marca ERPSERV). Modern CSS p/ Chrome/Browsershot. --}}
<style>
  :root{
    --orange:#EC6B2D; --orange-d:#D2541A;
    --ink:#1E1E1E; --ink-2:#3A3A3A; --gray:#7A7A7A; --line:#E2E2E2;
    --dark:#1B2027; --dark-2:#2A313B; --paper:#FFFFFF;
  }
  *{margin:0;padding:0;box-sizing:border-box;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
  html,body{font-family:'Calibri','Segoe UI',Arial,Helvetica,sans-serif;color:var(--ink);}
  @page{size:1280px 720px;margin:0;}
  .slide{position:relative;width:1280px;height:720px;overflow:hidden;background:var(--paper);page-break-after:always;}
  .slide:last-child{page-break-after:auto;}

  /* faixas/ípsilons de marca */
  .bar-orange{background:var(--orange);}
  .kicker{color:var(--orange);font-weight:700;letter-spacing:.16em;text-transform:uppercase;font-size:15px;}
  .h1{font-size:46px;font-weight:800;line-height:1.04;color:var(--ink);}
  .h1.on-dark{color:#fff;}
  .h2{font-size:30px;font-weight:800;color:var(--ink);}
  .muted{color:var(--gray);} .small{font-size:13px;} .tiny{font-size:11px;}

  /* cabeçalho interno padrão das páginas de conteúdo */
  .phead{position:absolute;top:54px;left:64px;}
  .phead .ttl{font-size:30px;font-weight:800;color:var(--ink);}
  .phead .ttl em{color:var(--orange);font-style:normal;}
  .pfoot{position:absolute;bottom:26px;left:64px;right:64px;display:flex;justify-content:space-between;align-items:center;color:var(--gray);font-size:11px;border-top:1px solid var(--line);padding-top:10px;}
  .wm{position:absolute;right:60px;top:46px;font-weight:800;font-size:18px;color:var(--ink);}
  .wm b{color:var(--orange);}

  /* logotipo ERPSERV recriado em CSS (EMF original não é usável em HTML) */
  .erp-logo{font-weight:800;font-size:30px;letter-spacing:-.5px;color:#fff;}
  .erp-logo b{color:var(--orange);}
  .erp-logo span{display:block;font-size:11px;letter-spacing:.42em;color:#cfd3d8;font-weight:600;margin-top:-4px;}
  .erp-logo.ink{color:var(--ink);} .erp-logo.ink span{color:var(--gray);}

  /* CAPA */
  .cover{background:var(--dark);}
  .cover .left{position:absolute;inset:0 46% 0 0;padding:70px 60px;display:flex;flex-direction:column;justify-content:space-between;}
  .cover .right{position:absolute;inset:0 0 0 54%;background:linear-gradient(135deg,#222a33,#11151b);}
  .cover .right .photo{position:absolute;inset:0;background:#000 center/cover no-repeat;filter:grayscale(1) contrast(1.05);opacity:.9;}
  .cover .right:after{content:'';position:absolute;left:-40px;top:0;bottom:0;width:80px;background:var(--dark);transform:skewX(-7deg);}
  .cover .badge{display:inline-block;background:var(--orange);color:#fff;font-weight:800;letter-spacing:.12em;padding:8px 16px;border-radius:3px;font-size:14px;}
  .cover .fields{display:grid;grid-template-columns:auto 1fr;gap:9px 18px;font-size:16px;color:#dfe3e8;margin-top:8px;}
  .cover .fields .k{color:var(--orange);font-weight:700;text-transform:uppercase;font-size:13px;letter-spacing:.06em;align-self:center;}
  .cover .logobox{position:absolute;right:48px;bottom:48px;width:190px;height:92px;border:2px dashed rgba(255,255,255,.35);border-radius:8px;display:flex;align-items:center;justify-content:center;color:rgba(255,255,255,.6);font-size:12px;text-align:center;}

  /* cards (dores / soluções) */
  .grid3{position:absolute;left:64px;right:64px;top:120px;display:grid;grid-template-columns:repeat(3,1fr);gap:18px;}
  .card{background:#fff;border:1px solid var(--line);border-top:4px solid var(--orange);border-radius:8px;padding:18px 18px 16px;box-shadow:0 6px 18px rgba(0,0,0,.04);}
  .card h4{font-size:15px;font-weight:800;color:var(--ink);margin-bottom:6px;text-transform:uppercase;letter-spacing:.02em;}
  .card p{font-size:12.5px;color:var(--ink-2);line-height:1.35;}
  .sol .card{border-top-color:var(--dark);}

  /* tabela investimento / pagamento */
  table.invest{position:absolute;left:64px;top:150px;width:560px;border-collapse:collapse;font-size:15px;}
  table.invest th{background:var(--dark);color:#fff;text-align:left;padding:11px 14px;font-size:12px;letter-spacing:.06em;text-transform:uppercase;}
  table.invest td{padding:11px 14px;border-bottom:1px solid var(--line);}
  table.invest tr.total td{background:#FCEDE3;font-weight:800;color:var(--orange-d);border:none;}
  .num{text-align:right;font-variant-numeric:tabular-nums;}

  .box{background:#F7F7F7;border-left:4px solid var(--orange);border-radius:6px;padding:14px 16px;font-size:12.5px;color:var(--ink-2);line-height:1.4;}
  .note-strip{position:absolute;left:64px;right:64px;bottom:90px;}
  ul.scope{position:absolute;left:64px;top:230px;width:560px;list-style:none;}
  ul.scope li{font-size:14px;color:var(--ink-2);padding:7px 0 7px 24px;position:relative;border-bottom:1px dashed var(--line);}
  ul.scope li:before{content:'';position:absolute;left:0;top:14px;width:9px;height:9px;background:var(--orange);border-radius:2px;}

  /* steps processos */
  .steps{position:absolute;left:64px;right:64px;top:170px;display:grid;grid-template-columns:repeat(5,1fr);gap:14px;}
  .step{background:#fff;border:1px solid var(--line);border-radius:8px;padding:14px;text-align:center;}
  .step .n{display:inline-flex;width:34px;height:34px;border-radius:50%;background:var(--orange);color:#fff;font-weight:800;align-items:center;justify-content:center;margin-bottom:8px;}
  .step .t{font-size:12px;font-weight:700;color:var(--ink);text-transform:uppercase;}

  /* aceite */
  .sign{position:absolute;top:150px;display:grid;gap:10px;width:430px;}
  .sign .ln{border-bottom:1.5px solid var(--ink);height:34px;}
  .sign .lbl{font-size:12px;color:var(--gray);text-transform:uppercase;letter-spacing:.05em;}
  .contratada{position:absolute;right:64px;top:150px;width:430px;background:#F7F7F7;border-radius:8px;padding:18px;font-size:12.5px;color:var(--ink-2);line-height:1.5;}
  .terms{position:absolute;left:64px;right:64px;bottom:54px;font-size:10.5px;color:var(--gray);line-height:1.4;}

  /* obrigado */
  .thanks{background:var(--dark);} .thanks .photo{position:absolute;inset:0;background:#000 center/cover;filter:grayscale(1);opacity:.35;}
  .thanks .c{position:absolute;left:64px;bottom:80px;color:#fff;}
  .thanks .c .big{font-size:60px;font-weight:800;} .thanks .c .big b{color:var(--orange);}
  .thanks .c .contact{margin-top:14px;font-size:15px;color:#dfe3e8;line-height:1.7;}
</style>
