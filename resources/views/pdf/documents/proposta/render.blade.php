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
  .slide > img.art{position:absolute;inset:0;width:1280px;height:720px;display:block;}
  /* overlays de campos dinâmicos */
  .ov{position:absolute;font-family:'Calibri','Segoe UI',Arial,sans-serif;}
  .ov.val{color:#10AAA5;font-weight:600;}     /* valores teal (capa) */
  .ov.dark{color:#3a2e5e;}
  .ov.num{color:#1E1E1E;text-align:right;}
</style></head><body>
  @foreach ($slides as $i => $uri)
    <div class="slide">
      <img class="art" src="{{ $uri }}" alt="slide {{ $i+1 }}">
      @if(!empty($overlays[$i])){!! $overlays[$i] !!}@endif
    </div>
  @endforeach
</body></html>
