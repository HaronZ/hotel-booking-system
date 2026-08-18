{{--
    Renders a custom date-picker trigger backed by a hidden <input>.
    The hidden input keeps its normal id/value semantics so app.js can read
    it exactly like a plain <input type="date"> everywhere else in the code
    - only how the value gets set and displayed is different.

    Props:
      id        - element id for the underlying hidden input (required)
      pairedMin - id of another date field whose value becomes this field's
                  minimum selectable date (e.g. check-out paired to check-in)
--}}
<button type="button" class="date-trigger" data-date-trigger-for="{{ $id }}">
    <span class="date-display-text">Select date</span>
    <svg class="date-icon" width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
        <rect x="2" y="3" width="12" height="11" rx="1.5" stroke="currentColor" stroke-width="1.3"/>
        <path d="M2 6.5H14" stroke="currentColor" stroke-width="1.3"/>
        <path d="M5 1.5V4M11 1.5V4" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>
    </svg>
</button>
<input type="hidden" id="{{ $id }}" @if(!empty($pairedMin)) data-paired-min="{{ $pairedMin }}" @endif>
