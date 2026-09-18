{{-- wire:ignore: the player owns this DOM; a Livewire re-render of the page must not morph it away. --}}
<div wire:ignore>
    <x-session-replay::player :session="$getRecord()" />
</div>
