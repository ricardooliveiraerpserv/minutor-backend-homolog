{{--
  Proposta — render orientado a ARTWORK (Fase 1.3 refino visual).
  Usa os SLIDES ORIGINAIS (SVG vetorial extraído do material institucional ERPSERV) como peça gráfica,
  com OVERLAY apenas dos campos dinâmicos. NÃO reconstrói diagramas de memória.
  $slides   = lista ordenada de data-URIs (SVG) dos slides do tipo.
  $overlays = mapa [indiceSlide => HTML de overlay posicionado] (campos dinâmicos).
--}}
@php
  $robotoUri = \App\Documents\DocumentAssets::dataUri('fonts/RobotoCondensed.ttf');
  $bebasUri  = \App\Documents\DocumentAssets::dataUri('fonts/BebasNeue.ttf');
  $escopoBgUri = \App\Documents\DocumentAssets::dataUri('escopo-bg.png');
@endphp
<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8"><title>Proposta {{ $codigo ?? '' }}</title>
<style>
  /* Fontes REAIS do deck ERPSERV (embutidas — render idêntico, offline). */
  @font-face{font-family:'Roboto Condensed';font-weight:100 900;font-style:normal;src:url('{{ $robotoUri }}') format('truetype');}
  @font-face{font-family:'Bebas Neue';font-weight:400;font-style:normal;src:url('{{ $bebasUri }}') format('truetype');}
  *{margin:0;padding:0;box-sizing:border-box;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
  @page{size:1280px 720px;margin:0;}
  html,body{font-family:'Roboto Condensed','Segoe UI',Arial,Helvetica,sans-serif;}
  .slide{position:relative;width:1280px;height:720px;overflow:hidden;page-break-after:always;background:#fff;}
  .slide:last-child{page-break-after:auto;}
  /* separação visual entre páginas só na TELA (preview no iframe) — não afeta o PDF */
  @media screen{body{background:#e5e7eb;}.slide{margin:0 auto 14px;box-shadow:0 1px 6px rgba(0,0,0,.18);}}
  .slide > img.art{position:absolute;inset:0;width:1280px;height:720px;display:block;}
  /* overlays de campos dinâmicos */
  .ov{position:absolute;font-family:'Calibri','Segoe UI',Arial,sans-serif;}
  .ov.val{color:#10AAA5;font-weight:600;}     /* valores teal (capa) */
  .ov.dark{color:#3a2e5e;}
  .ov.num{color:#1E1E1E;text-align:right;}
  /* ===== Escopo funcional: blocos que fluem (texto/imagem) + páginas de continuação ===== */
  .eb-line{margin:0 0 3px;}
  .eb-gap{height:10px;}
  .eb-img{margin:6px 0 12px;break-inside:avoid;}
  .eb-img img{border-radius:4px;}
  .eb-img figcaption{font-size:12px;color:#7a7a90;margin-top:4px;}
  /* página de continuação do escopo (mesma identidade do deck) */
  /* fundo padrão do deck (imagem real fornecida) — igual aos demais slides */
  .escopo-cont, .aceite-cont{
    background:#ffffff url('{{ $escopoBgUri }}') no-repeat top left;
    background-size:1280px 720px;
  }
  .escopo-cont .ec-head, .aceite-cont .ec-head{position:absolute;left:0;top:0;width:1280px;height:96px;background:#442B7E;}
  .escopo-cont .ec-head .ec-title, .aceite-cont .ec-head .ec-title{position:absolute;left:80px;top:30px;color:#fff;font-family:'Bebas Neue','Roboto Condensed',sans-serif;font-size:38px;letter-spacing:2px;}
  .escopo-cont .ec-head .ec-sub, .aceite-cont .ec-head .ec-sub{position:absolute;left:82px;top:74px;color:#10AAA5;font-family:'Roboto Condensed',sans-serif;font-weight:300;font-size:13px;letter-spacing:1px;}
  .escopo-cont .ec-rule, .aceite-cont .ec-rule{position:absolute;left:0;top:96px;width:1280px;height:6px;background:#10AAA5;}
  .escopo-cont .ec-foot, .aceite-cont .ec-foot{position:absolute;left:0;top:690px;width:1280px;height:30px;background:#442B7E;}
  .escopo-cont [data-escopo-cont-box]{position:absolute;left:90px;top:132px;width:1100px;height:540px;overflow:hidden;font-family:'Roboto Condensed';font-weight:300;font-size:16px;line-height:1.4;color:#4a4a66;}
  /* ===== ACEITE (HTML reflui no lugar da arte): corpo flui e empurra assinatura p/ baixo; pagina no overflow ===== */
  .aceite-cont [data-aceite-box], .aceite-cont [data-aceite-cont-box], .aceite-cont [data-invest-cont-box], .aceite-cont [data-prazo-cont-box]{position:absolute;left:80px;top:122px;width:1120px;height:548px;overflow:hidden;font-family:'Roboto Condensed';font-weight:300;font-size:13.5px;line-height:1.3;color:#595959;}
  .aceite-cont .ac-sec{color:#10AAA5;font-weight:700;font-size:14px;letter-spacing:.3px;margin:0 0 5px;}
  .aceite-cont .ac-mt{margin-top:13px;}
  .aceite-cont .ac-p{margin:0 0 9px;}
  .aceite-cont .ac-p a{color:#3f6fb0;font-style:italic;text-decoration:underline;word-break:break-all;}
  .aceite-cont .ac-hl{display:inline-block;background:#442B7E;color:#fff;font-weight:700;padding:3px 9px;margin:0 0 9px;}
  .aceite-cont .ac-grp{break-inside:avoid;}
  .aceite-cont .ac-tbl{margin:0 0 6px;break-inside:avoid;}
  .aceite-cont .ac-th{background:#442B7E;color:#fff;text-align:center;font-weight:700;font-size:12.5px;letter-spacing:1px;padding:6px 0;}
  .aceite-cont .ac-bd{background:#cfd5e9;padding:10px 16px;}
  .aceite-cont .ac-bd.ac-sign{min-height:60px;}
  .aceite-cont .ac-row{display:flex;padding:6px 0;}
  .aceite-cont .ac-row .c1{flex:1;}
  .aceite-cont .ac-row .c2{width:320px;}
  .aceite-cont .ac-row b{font-weight:700;color:#3a3a3a;}
  /* ===== Manifesto de Assinaturas (P-E.2.4) — página(s) de comprovante estilo cartório, branco ===== */
  .sign-doc{background:#fff;font-family:'Roboto Condensed','Segoe UI',Arial,sans-serif;color:#2a2a3a;}
  .sign-doc .mf-pad{position:absolute;left:96px;top:64px;width:1088px;height:600px;overflow:hidden;}
  .sign-doc .mf-top{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:1px solid #e5e7eb;padding-bottom:14px;}
  .sign-doc .mf-brand{font-family:'Bebas Neue','Roboto Condensed',sans-serif;font-size:30px;letter-spacing:2px;color:#442B7E;}
  .sign-doc .mf-brand small{display:block;font-family:'Roboto Condensed';font-size:11px;letter-spacing:1px;color:#10AAA5;font-weight:600;}
  .sign-doc .mf-meta{text-align:right;font-size:11px;color:#9aa0ab;line-height:1.5;}
  .sign-doc .mf-title{font-family:'Bebas Neue','Roboto Condensed',sans-serif;font-size:26px;letter-spacing:1px;margin-top:16px;color:#2a2a3a;}
  .sign-doc .mf-hash{font-size:11px;color:#9aa0ab;margin-top:4px;word-break:break-all;}
  .sign-doc .mf-hash b{color:#6b7280;font-weight:700;}
  .sign-doc .mf-h{font-family:'Bebas Neue','Roboto Condensed',sans-serif;font-size:22px;letter-spacing:1px;color:#442B7E;margin:22px 0 10px;}
  .sign-doc .mf-sig{display:flex;align-items:flex-start;gap:12px;padding:10px 0;border-bottom:1px solid #f1f1f4;break-inside:avoid;}
  .sign-doc .mf-check{width:24px;height:24px;border-radius:50%;border:2px solid #16A34A;color:#16A34A;font-size:14px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
  .sign-doc .mf-sig .nm{font-weight:700;font-size:16px;color:#2a2a3a;}
  .sign-doc .mf-sig .dt{font-size:13px;color:#595959;}
  .sign-doc .mf-sig img.traco{height:40px;max-width:220px;object-fit:contain;margin-top:4px;display:block;}
  .sign-doc .mf-log .row{display:flex;gap:16px;padding:7px 0;border-bottom:1px solid #f5f5f7;font-size:12px;break-inside:avoid;}
  .sign-doc .mf-log .ts{width:160px;flex-shrink:0;color:#9aa0ab;}
  .sign-doc .mf-log .tx{flex:1;color:#4a4a5a;line-height:1.45;}
  .sign-doc .mf-foot{margin-top:18px;padding-top:12px;border-top:1px solid #e5e7eb;font-size:11px;color:#7a7a90;line-height:1.5;}
  .sign-doc .mf-foot b{color:#2a2a3a;}
</style></head><body>
  @foreach ($slides as $i => $uri)
    @continue(in_array($i, $paginasOff ?? []))
    @if(isset($escopoIndex) && $i === $escopoIndex && !empty($escopoPage))
      {{-- Página do ESCOPO: HTML branded (cabeçalho no topo) no lugar da arte do deck. --}}
      {!! $escopoPage !!}
    @elseif(isset($aceiteIndex) && $i === $aceiteIndex && !empty($aceitePage))
      {{-- Página do ACEITE: HTML branded que reflui (texto opcional empurra assinatura p/ baixo). --}}
      {!! $aceitePage !!}
    @else
      <div class="slide">
        <img class="art" src="{{ $uri }}" alt="slide {{ $i+1 }}">
        @if(!empty($overlays[$i])){!! $overlays[$i] !!}@endif
      </div>
    @endif
  @endforeach

  {{-- P-E.2.4 — MANIFESTO DE ASSINATURAS (estilo comprovante): Assinaturas + Log + validade jurídica. --}}
  @if(!empty($manifesto) && !empty($manifesto['assinaturas']))
    <div class="slide sign-doc">
      <div class="mf-pad" data-sign-box>
        <div class="mf-top">
          <div class="mf-brand">Minutor<small>ERPSERV · COMPROVANTE DE ASSINATURA</small></div>
          <div class="mf-meta">Datas e horários em GMT -03:00 (Brasília)<br>Comprovante gerado em {{ $manifesto['gerado_em'] }}</div>
        </div>
        <div class="mf-title">Proposta {{ $manifesto['codigo'] }}</div>
        @if(!empty($manifesto['hash']))<div class="mf-hash"><b>Hash do documento (SHA-256):</b> {{ $manifesto['hash'] }}</div>@endif

        <div class="mf-h">Assinaturas</div>
        @foreach ($manifesto['assinaturas'] as $a)
          <div class="mf-sig">
            <div class="mf-check">&#10003;</div>
            <div>
              <div class="nm">{{ $a['nome'] }}</div>
              @if(!empty($a['cpf']))<div class="dt">CPF: {{ $a['cpf'] }}</div>@endif
              <div class="dt">Assinou como {{ $a['como'] }} em {{ $a['data'] }}</div>
              @if(!empty($a['image']))<img class="traco" src="{{ $a['image'] }}" alt="assinatura">@endif
            </div>
          </div>
        @endforeach

        @if(!empty($manifesto['log']))
          <div class="mf-h">Log</div>
          <div class="mf-log">
            @foreach ($manifesto['log'] as $l)
              <div class="row"><div class="ts">{{ $l['data'] }}</div><div class="tx">{{ $l['texto'] }}</div></div>
            @endforeach
          </div>
        @endif

        <div class="mf-foot">
          <b>Documento assinado eletronicamente com validade jurídica.</b> As assinaturas eletrônicas têm validade jurídica prevista na Medida Provisória nº 2.200-2/2001. Este comprovante integra a proposta {{ $manifesto['codigo'] }} e registra os signatários e o histórico da formalização.
        </div>
      </div>
    </div>
  @endif

  {{-- Template da página de CONTINUAÇÃO do escopo (clonado pelo paginador quando o texto/imagens estouram a caixa). --}}
  <template id="escopo-cont-tpl">
    <div class="slide escopo-cont">
      <div class="ec-head"><div class="ec-title">ESCOPO</div><div class="ec-sub">CONTINUAÇÃO</div></div>
      <div class="ec-rule"></div>
      <div data-escopo-cont-box></div>
      <div class="ec-foot"></div>
    </div>
  </template>

  {{-- Template da página de CONTINUAÇÃO do ACEITE (mesma identidade branded; corpo flui igual à 1ª pág). --}}
  <template id="aceite-cont-tpl">
    <div class="slide aceite-cont">
      <div class="ec-head"><div class="ec-title">ACEITE</div><div class="ec-sub">CONTINUAÇÃO</div></div>
      <div class="ec-rule"></div>
      <div data-aceite-cont-box></div>
      <div class="ec-foot"></div>
    </div>
  </template>

  {{-- Continuação de INVESTIMENTO / PRAZO (extras que não couberam na área livre da página). --}}
  <template id="invest-cont-tpl">
    <div class="slide aceite-cont">
      <div class="ec-head"><div class="ec-title">INVESTIMENTO</div><div class="ec-sub">CONTINUAÇÃO</div></div>
      <div class="ec-rule"></div>
      <div data-invest-cont-box></div>
      <div class="ec-foot"></div>
    </div>
  </template>
  <template id="prazo-cont-tpl">
    <div class="slide aceite-cont">
      <div class="ec-head"><div class="ec-title">PRAZO E PAGAMENTO</div><div class="ec-sub">CONTINUAÇÃO</div></div>
      <div class="ec-rule"></div>
      <div data-prazo-cont-box></div>
      <div class="ec-foot"></div>
    </div>
  </template>

  {{-- Continuação do MANIFESTO de assinaturas (página branca, sem cabeçalho). --}}
  <template id="sign-cont-tpl">
    <div class="slide sign-doc">
      <div class="mf-pad" data-sign-cont-box></div>
    </div>
  </template>

  {{-- Paginador: distribui os blocos do escopo na caixa da pág. 1 e cria páginas de continuação
       até tudo caber. Roda no Chromium (Gotenberg aguarda window.__escopoPaged). --}}
  <script>
    (function(){
      function overflows(el){ return el.scrollHeight > el.clientHeight + 1; }
      // Distribui os blocos de uma caixa (escopo OU aceite) e cria páginas de continuação até tudo caber.
      // origin pode ser uma faixa pequena (gap do aceite): nesse caso até um único bloco que estoure vai p/ continuação.
      function paginate(boxSel, tplId, contBoxSel){
        var box = document.querySelector(boxSel);
        if(!box) return;
        var tpl = document.getElementById(tplId);
        if(!tpl) return;
        var slide = box.closest('.slide');
        var blocks = Array.prototype.slice.call(box.children);
        for(var k=0;k<blocks.length;k++){ box.removeChild(blocks[k]); }
        function addPage(){
          var node = tpl.content.firstElementChild.cloneNode(true);
          slide.parentNode.insertBefore(node, slide.nextSibling);
          slide = node;
          return node.querySelector(contBoxSel);
        }
        var cur = box;
        for(var j=0;j<blocks.length;j++){
          var bl = blocks[j];
          cur.appendChild(bl);
          // move quando estoura E (há outro bloco antes OU estamos na faixa de origem — que pode ser pequena demais p/ 1 bloco)
          if(overflows(cur) && (cur.children.length > 1 || cur === box)){
            cur.removeChild(bl);
            cur = addPage();
            cur.appendChild(bl);
          }
        }
      }
      var __ran = false;
      function runPaginators(){
        if (__ran) return; __ran = true;
        try{
          paginate('[data-escopo-box]', 'escopo-cont-tpl', '[data-escopo-cont-box]');
          paginate('[data-aceite-box]', 'aceite-cont-tpl', '[data-aceite-cont-box]');
          paginate('[data-invest-box]', 'invest-cont-tpl', '[data-invest-cont-box]');
          paginate('[data-prazo-box]', 'prazo-cont-tpl', '[data-prazo-cont-box]');
          paginate('[data-sign-box]', 'sign-cont-tpl', '[data-sign-cont-box]');
        }catch(e){ /* nunca trava o render */ }
        window.__escopoPaged = true;
      }
      // mede DEPOIS das fontes carregarem (senão o preview/iframe mede com fonte fallback e não quebra direito).
      if (document.fonts && document.fonts.ready && document.fonts.ready.then) { document.fonts.ready.then(runPaginators); setTimeout(runPaginators, 1500); }
      else { runPaginators(); }
    })();
  </script>
</body></html>
