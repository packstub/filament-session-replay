{{-- wire:ignore: the player owns this DOM; a Livewire re-render of the page must not morph it away. --}}
{{-- The mouse trail and clicks in the panel's primary colour. --}}
<div wire:ignore>
    <x-session-replay::player :session="$getRecord()" style="--sr-pointer: var(--primary-500)" />
</div>
