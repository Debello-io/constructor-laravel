{{-- One element with Constructor's data-cnstrc-* attributes around your own markup. --}}
<{{ $tag }} {{ $attributes->merge($presentAttributes()) }}>{{ $slot }}</{{ $tag }}>
@if (($json = $trackingData()) !== null)
<script id="cnstrc-data" type="application/json">{!! $json !!}</script>
@endif
