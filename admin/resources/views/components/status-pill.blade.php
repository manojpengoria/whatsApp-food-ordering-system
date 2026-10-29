@props(['status'])
<span {{ $attributes->merge(['class' => 'pill '.$status->badgeClasses()]) }}>
    <span class="size-1.5 rounded-full {{ $status->dotClass() }}"></span>{{ $status->label() }}
</span>
