@php
    use Illuminate\Support\Str;

    $iconBase = function($assoc) {
        $base = $assoc->platform
            ?? $assoc->name
            ?? $assoc->slug
            ?? $assoc->title
            ?? 'link';

        return Str::of($base)
            ->replace('default ', '')
            ->lower()
            ->replace([' ', '/', '\\'], '-');
    };
@endphp

@if(!empty($link->associated))
  <div class="associated-icons">
    @foreach($link->associated as $assoc)
      @php $basename = $iconBase($assoc); @endphp
      <a class="associated-icon button-hover icon-hover"
         id="assoc-{{ $link->id }}-{{ $assoc->id }}"
         rel="noopener noreferrer nofollow noindex"
         href="{{ $assoc->link }}"
         @if(\App\Models\UserData::getData($userinfo->id, 'links-new-tab')) target="_blank" @endif>
        <img alt="{{ $basename }}" class="icon hvr-icon"
             src="@if(theme('use_custom_icons') == 'true')
                      {{ url('themes/'.$GLOBALS['themeName'].'/extra/custom-icons') }}/{{ $basename }}{{ theme('custom_icon_extension') }}
                  @else
                      {{ asset('assets/linkstack/icons/'.$basename.'.svg') }}
                  @endif"
             onerror="this.onerror=null; this.src='{{ asset('assets/linkstack/icons/website.svg') }}';">
      </a>
    @endforeach
  </div>
@endif
