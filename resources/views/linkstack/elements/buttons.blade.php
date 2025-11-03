<?php use App\Models\UserData; ?>

@php 
$initial = 1; 
@endphp

@include('linkstack.modules.block-libraries', ['links' => $links])

@foreach($links as $link)
  @if(isset($link->custom_html) && $link->custom_html)
      @if(isset($link->ignore_container) && $link->ignore_container)
      </div></div></div>
      @endif
          @php setBlockAssetContext($link->type); @endphp
          @include('blocks::' . $link->type . '.display', ['link' => $link, 'initial' => $initial++])
      @if(isset($link->ignore_container) && $link->ignore_container)
      <div class="container"><div class="row"><div class="column">
      @endif
  @else
      @switch($link->name)
          @case('icon')
              @break
          @case('vcard')
              <div style="--delay: {{ $initial++ }}s" class="button-entrance  button-with-icons">
                  <div class="button-container"><a id="{{ $link->id }}" class="button button-default button-click button-hover icon-hover" rel="noopener noreferrer nofollow noindex" href="{{ route('vcard') . '/' . $link->id }}"><img alt="{{ $link->name }}" class="icon hvr-icon" src="@if(theme('use_custom_icons') == "true"){{ url('themes/' . $GLOBALS['themeName'] . '/extra/custom-icons')}}/vcard{{theme('custom_icon_extension')}} @else{{ asset('\/assets/linkstack/icons\/')}}vcard.svg @endif"></i>{{ $link->title }}</a></div>
               @include('linkstack.elements.associated-icons', ['link' => $link, 'userinfo' => $userinfo])</div>
              @break
          @case('phone')
              <div style="--delay: {{ $initial++ }}s" class="button-entrance button-with-icons">
                   <div class="button-container">
                  <a id="{{ $link->id }}" class="button button-default button-click button-hover icon-hover" rel="noopener noreferrer nofollow noindex" href="{{ $link->link }}"><img alt="{{ $link->name }}" class="icon hvr-icon" src="@if(theme('use_custom_icons') == "true"){{ url('themes/' . $GLOBALS['themeName'] . '/extra/custom-icons')}}/phone{{theme('custom_icon_extension')}} @else{{ asset('\/assets/linkstack/icons\/')}}phone.svg @endif"></i>{{ $link->title }}</a></div>
              @include('linkstack.elements.associated-icons', ['link' => $link, 'userinfo' => $userinfo])</div>
              @break
          @case('custom')
              @if($link->custom_css === "" or $link->custom_css === "NULL" or (theme('allow_custom_buttons') == "false"))
                  <div style="--delay: {{ $initial++ }}s" class="button-entrance  button-with-icons">
                       <div class="button-container">
                      <a id="{{ $link->id }}" class="button button-custom button-click button-hover icon-hover" rel="noopener noreferrer nofollow noindex" href="{{ $link->link }}" @if((UserData::getData($userinfo->id, 'links-new-tab') != false))target="_blank"@endif ><i style="color: {{$link->custom_icon}}" class="icon hvr-icon fa {{$link->custom_icon}}"></i>{{ $link->title }}</a></div>
                   @include('linkstack.elements.associated-icons', ['link' => $link, 'userinfo' => $userinfo])</div>
                  @break
              @elseif($link->custom_css != "")
                  <div style="--delay: {{ $initial++ }}s" class="button-entrance  button-with-icons">
                       <div class="button-container">
                      <a id="{{ $link->id }}" class="button button-custom button-click button-hover icon-hover" style="{{ $link->custom_css }}" rel="noopener noreferrer nofollow noindex" href="{{ $link->link }}" @if((UserData::getData($userinfo->id, 'links-new-tab') != false))target="_blank"@endif ><i style="color: {{$link->custom_icon}}" class="icon hvr-icon fa {{$link->custom_icon}}"></i>{{ $link->title }}</a></div>
                   @include('linkstack.elements.associated-icons', ['link' => $link, 'userinfo' => $userinfo])</div>
                  @break
              @endif
          @case('custom_website')
              @if($link->custom_css === "" or $link->custom_css === "NULL" or (theme('allow_custom_buttons') == "false"))
                  <div style="--delay: {{ $initial++ }}s" class="button-entrance  button-with-icons">
                       <div class="button-container">
                      <a id="{{ $link->id }}" class="button button-custom_website button-click button-hover icon-hover" rel="noopener noreferrer nofollow noindex" href="{{ $link->link }}" @if((UserData::getData($userinfo->id, 'links-new-tab') != false))target="_blank"@endif ><img alt="{{ $link->name }}" class="icon hvr-icon" src="@if(file_exists(base_path("assets/favicon/icons/").localIcon($link->id))){{url('assets/favicon/icons/'.localIcon($link->id))}}@else{{getFavIcon($link->id)}}@endif" onerror="this.onerror=null; this.src='{{asset('assets/linkstack/icons/website.svg')}}';">{{ $link->title }}</a></div>
                   @include('linkstack.elements.associated-icons', ['link' => $link, 'userinfo' => $userinfo])</div>
                  @break
              @elseif($link->custom_css != "")
                  <div style="--delay: {{ $initial++ }}s" class="button-entrance  button-with-icons">
                       <div class="button-container">
                      <a id="{{ $link->id }}" class="button button-custom_website button-click button-hover icon-hover" style="{{ $link->custom_css }}" rel="noopener noreferrer nofollow noindex" href="{{ $link->link }}" @if((UserData::getData($userinfo->id, 'links-new-tab') != false))target="_blank"@endif ><img alt="{{ $link->name }}" class="icon hvr-icon" src="@if(file_exists(base_path("assets/favicon/icons/").localIcon($link->id))){{url('assets/favicon/icons/'.localIcon($link->id))}}@else{{getFavIcon($link->id)}}@endif" onerror="this.onerror=null; this.src='{{asset('assets/linkstack/icons/website.svg')}}';">{{ $link->title }}</a></div>
                   @include('linkstack.elements.associated-icons', ['link' => $link, 'userinfo' => $userinfo])</div>
                  @break
              @endif
          @default
              <div style="--delay: {{ $initial++ }}s" class="button-entrance  button-with-icons">
                   <div class="button-container">
                  <a id="{{ $link->id }}" class="button button-{{ $link->name }} button-click button-hover icon-hover" rel="noopener noreferrer nofollow noindex" href="{{ $link->link }}" @if((UserData::getData($userinfo->id, 'links-new-tab') != false))target="_blank"@endif ><img alt="{{ $link->name }}" class="icon hvr-icon" src="@if(theme('use_custom_icons') == "true"){{ url('themes/' . $GLOBALS['themeName'] . '/extra/custom-icons')}}/{{str_replace('default ','',$link->name)}}{{theme('custom_icon_extension')}} @else{{ asset('\/assets/linkstack/icons\/') . str_replace('default ','',$link->name) }}.svg @endif">{{ $link->title }}</a></div>
               @include('linkstack.elements.associated-icons', ['link' => $link, 'userinfo' => $userinfo])</div>
      @endswitch
  @endif



@endforeach

<style>
:root{
  --assoc-size: 44px;   /* size of each small icon box */
  --assoc-gap: 10px;    /* gap between items / columns */
  --assoc-radius: 10px; /* rounding for the small icon box */
}

/* One row = two columns (70% / 30%) */
.button-entrance.button-with-icons{
  display: grid;
  grid-template-columns: 70% 30%;
  column-gap: var(--assoc-gap);
  align-items: center;            /* vertical center both columns */
  width: 100%;   
  /* let parent .column control the 600px */
}

/* Column 1: big button fills its column; contents aligned right */
.button-entrance.button-with-icons .button-container{
  min-width: 0;                   /* prevent overflow in grids */
}
.button-entrance.button-with-icons .button-container > .button{
  display: flex;                  /* so we can align inner content */
  justify-content: center;      /* ⬅ move text/icon to the right */
  align-items: center;
  width: 100%;
  box-sizing: border-box;
}

/* Column 2: small icons bay aligned left, wraps if many */
.button-entrance.button-with-icons .associated-icons{
  display: flex;
  justify-content: flex-start;    /* ⬅ left align in the 30% column */
  align-items: center;
  gap: var(--assoc-gap);
  flex-wrap: wrap;                /* allow multiple icons to wrap */
}

/* Each tiny icon box */
.associated-icon{
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: var(--assoc-size);
  height: var(--assoc-size);
  border-radius: var(--assoc-radius);
  box-shadow: 0 1px 2px rgba(0,0,0,.2);
  overflow: hidden;                /* rounds the image itself */
  text-decoration: none;
}

/* Image (or <i>) inside the small box */
.associated-icon .icon{
  width: 70%;
  height: 70%;
  object-fit: contain;
  line-height: 1;
  text-align: center;
    align-items: center;
  justify-content: center;
}

/* Ensure main button hover does not affect the icon column */
.button-entrance.button-with-icons .button:hover ~ .associated-icons {
  filter: none;
  transform: none;
}

/* Optional: keep rows aligned on small screens by stacking */
@media (max-width: 560px){
  .button-entrance.button-with-icons{
    grid-template-columns: 1fr;   /* stack vertically */
    row-gap: var(--assoc-gap);
  }
  .button-entrance.button-with-icons .button-container > .button{
    justify-content: center;
  }
  .button-entrance.button-with-icons .associated-icons{
    justify-content: center;
  }
}



</style>

<script>
  // (leave your existing click tracking script as-is)
  document.addEventListener('DOMContentLoaded', function () {
    function handleClickOrTouch(event) {
      if (event.target.classList.contains('button-click')) {
        var id = event.target.id;
        if (!sessionStorage.getItem('clicked-' + id)) {
          var url = '{{ route("clickNumber") }}/' + id;
          fetch(url, { method: 'GET', headers: { 'Content-Type': 'application/json' } });
          sessionStorage.setItem('clicked-' + id, 'true');
        }
      }
    }
    document.addEventListener('mousedown', function (event) {
      if (event.button === 0 || event.button === 1) { handleClickOrTouch(event); }
    });
    document.addEventListener('touchstart', handleClickOrTouch);
  });
</script>
