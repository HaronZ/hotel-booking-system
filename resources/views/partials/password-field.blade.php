{{--
    A password <input> with a show/hide toggle button.

    Props:
      id         - element id for the input (required)
      minlength  - optional minlength attribute
--}}
<div class="password-field">
    <input type="password" id="{{ $id }}" required @if(!empty($minlength)) minlength="{{ $minlength }}" @endif>
    <button type="button" class="password-toggle" data-password-toggle-for="{{ $id }}" aria-label="Show password">
        <svg class="icon-eye" width="17" height="17" viewBox="0 0 20 20" fill="none" aria-hidden="true">
            <path d="M1 10s3.5-6 9-6 9 6 9 6-3.5 6-9 6-9-6-9-6Z" stroke="currentColor" stroke-width="1.4"/>
            <circle cx="10" cy="10" r="2.5" stroke="currentColor" stroke-width="1.4"/>
        </svg>
        <svg class="icon-eye-off" width="17" height="17" viewBox="0 0 20 20" fill="none" aria-hidden="true">
            <path d="M1 10s3.5-6 9-6 9 6 9 6-3.5 6-9 6-9-6-9-6Z" stroke="currentColor" stroke-width="1.4"/>
            <circle cx="10" cy="10" r="2.5" stroke="currentColor" stroke-width="1.4"/>
            <path d="M3 3l14 14" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
        </svg>
    </button>
</div>
