@props(['label'])
<div class="carousel-controls" data-carousel-controls>
    <button type="button" class="carousel-btn" data-carousel-prev aria-label="Previous {{ $label }}" title="Previous">
        <x-icon name="chevron-right" :size="18" class="flip" />
    </button>
    <button type="button" class="carousel-btn" data-carousel-next aria-label="Next {{ $label }}" title="Next">
        <x-icon name="chevron-right" :size="18" />
    </button>
</div>
