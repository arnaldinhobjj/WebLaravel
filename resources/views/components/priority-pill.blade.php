@props(['priority'])
<span {{ $attributes->merge(['class' => 'pill priority-'.$priority->value]) }}>{{ $priority->label() }}</span>
