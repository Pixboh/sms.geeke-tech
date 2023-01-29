<!-- BEGIN: Footer-->
<footer
        class="footer footer-light {{ $configData['footerType'] === 'footer-hidden' ? 'd-none' : '' }} {{ $configData['footerType'] }}">
    <p class="clearfix mb-0">
        <span class="float-md-left d-block d-md-inline-block mt-25"> {!! config('app.footer_text') !!}
            <a class="ms-25" href="{{ route('login') }}">{{ config('app.name') }},</a>
            <span class="d-none d-sm-inline-block">{{ __('locale.labels.all_rights_reserved') }}</span>
        </span>

        <span class="float-md-end d-none d-md-block">
            @if(config('app.terms_of_use'))
                <a class="ms-25" target="_blank" href="{{ config('app.terms_of_use') }}">{{ __('locale.labels.terms_of_use') }}</a>
            @endif

            @if(config('app.privacy_policy'))
                <a class="ms-25" target="_blank" href="{{ config('app.privacy_policy') }}">{{ __('locale.labels.privacy_policy') }}</a>
            @endif
        </span>
    </p>
</footer>
<button class="btn btn-primary btn-icon scroll-top" type="button"><i data-feather="arrow-up"></i></button>
<!-- END: Footer-->
