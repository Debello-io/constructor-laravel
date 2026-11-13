{{-- Constructor's request object and result ID for the beacon. The JSON is \u-escaped for <script>. --}}
@if (($json = $payload()) !== null)
<script id="cnstrc-data" type="application/json">{!! $json !!}</script>
@endif
