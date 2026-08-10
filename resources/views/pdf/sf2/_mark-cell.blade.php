@php
    $mark = $mark ?? 'present';
@endphp
<td class="day-cell">
    @if($mark === 'absent')
        <span class="mark-x">x</span>
    @elseif($mark === 'tardy')
        <div class="mark-tardy"></div>
    @elseif($mark === 'half')
        <span class="mark-x">H</span>
    @endif
    <div class="cell-diagonal"></div>
</td>
