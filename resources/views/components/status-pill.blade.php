@props(['status'])
<span {{ $attributes->merge(['class' => 'pill status-'.$status->value]) }}><span class="pill-dot" aria-hidden="true"></span>{{ $status->label() }}</span>
