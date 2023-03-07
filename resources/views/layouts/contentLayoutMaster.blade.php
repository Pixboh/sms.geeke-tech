@isset($pageConfigs)
{!! Helper::updatePageConfig($pageConfigs) !!}
@endisset
        <!DOCTYPE html>
@php
    $configData = Helper::applClasses();
@endphp

<html class="loading {{ ($configData['theme'] === 'light') ? '' : $configData['layoutTheme']}}"
      lang="@if(Session::has('locale')){{Session::get('locale')}}@else{{config('app.locale')}}@endif"
      data-textdirection="{{ env('MIX_CONTENT_DIRECTION') === 'rtl' ? 'rtl' : 'ltr' }}"
      @if($configData['theme'] === 'dark') data-layout="dark-layout" @endif>

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <meta name="keywords" content="{{config('app.keyword')}}"/>
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title') - {{config('app.title')}}</title>
    <link rel="shortcut icon" type="image/x-icon" href="<?php echo asset(config('app.favicon')); ?>"/>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,300;0,400;0,500;0,600;1,400;1,500;1,600"
          rel="stylesheet">
    <!-- Smartsupp Live Chat script -->
    <script type="text/javascript">
        var _smartsupp = _smartsupp || {};
        _smartsupp.key = '44282f8a7866d6df70b9628eebb81556b30f2061';
        window.smartsupp || (function (d) {
            var s, c, o = smartsupp = function () {
                o._.push(arguments)
            };
            o._ = [];
            s = d.getElementsByTagName('script')[0];
            c = d.createElement('script');
            c.type = 'text/javascript';
            c.charset = 'utf-8';
            c.async = true;
            c.src = 'https://www.smartsuppchat.com/loader.js?';
            s.parentNode.insertBefore(c, s);

            smartsupp('name', "{{Auth::user()->displayName()}}");
            smartsupp('email', "{{ Auth::user()->email}}");
            smartsupp('phone', "{{ Auth::user()->customer->phone}}");
            smartsupp('variables', {
                Custom_url: "{{route('admin.customers.show', Auth::user()->uid)}}"
            });


        })(document);


    </script>
    {{-- Include core + vendor Styles --}}
    @include('panels/styles')

</head>
<!-- END: Head-->

<!-- BEGIN: Body-->
@isset($configData["mainLayoutType"])
    @extends((( $configData["mainLayoutType"] === 'horizontal') ? 'layouts.horizontalLayoutMaster' : 'layouts.verticalLayoutMaster' ))
@endisset
@if(Helper::app_config('custom_script') != '')
    {!! Helper::app_config('custom_script') !!}
@endif