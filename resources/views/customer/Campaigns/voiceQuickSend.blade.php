@extends('layouts/contentLayoutMaster')

@section('title', __('locale.menu.Quick Send'))

@section('vendor-style')
    <!-- vendor css files -->
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/forms/select/select2.min.css')) }}">
@endsection

@section('page-style')

    <style>
        .customized_select2 .select2-selection--single, .input_sender_id {
            border-left: 0;
            border-radius: 0 4px 4px 0;
            min-height: 2.75rem !important;
        }
    </style>

@endsection

@section('content')

    <!-- Basic Vertical form layout section start -->
    <section id="basic-vertical-layouts campaign_builder">
        <div class="row match-height">
            <div class="col-md-8 col-12">
                <div class="card">
                    <div class="card-content">
                        <div class="card-body">

                            <form class="form form-vertical" action="{{ route('customer.voice.quick_send') }}" method="post">
                                @csrf
                                <div class="row">


                                    <div class="col-12">
                                        <div class="mb-1">
                                            <label for="sending_server" class="form-label required">{{ __('locale.labels.sending_server') }}</label>
                                            <select class="select2 form-select" name="sending_server">
                                                @foreach($sending_server as $server)
                                                    <option value="{{$server->id}}"> {{ $server->name }}</option>
                                                @endforeach
                                            </select>

                                            @error('sending_server')
                                            <p><small class="text-danger">{{ $message }}</small></p>
                                            @enderror
                                        </div>
                                    </div>

                                    <div class="col-12">
                                        <div class="mb-1">
                                            <label for="recipient" class="form-label required">{{ __('locale.labels.recipient') }}</label>
                                            <div class="input-group">
                                                <div style="width: 8rem">
                                                    <select class="form-select select2" id="country_code" name="country_code">
                                                        @foreach($coverage as $code)
                                                            <option value="{{ $code->country_id }}"> +{{ $code->country->country_code }} </option>
                                                        @endforeach
                                                    </select>
                                                </div>

                                                <input type="text"
                                                       id="recipient"
                                                       class="form-control @error('recipient') is-invalid @enderror"
                                                       value="{{ old('recipient',  $recipient ?? null) }}"
                                                       name="recipient"
                                                       required
                                                       placeholder="{{__('locale.labels.required')}}"
                                                >

                                            </div>

                                            @error('recipient')
                                            <p><small class="text-danger">{{ $message }}</small></p>
                                            @enderror
                                            @error('country_code')
                                            <p><small class="text-danger">{{ $message }}</small></p>
                                            @enderror
                                        </div>
                                    </div>


                                    @if(auth()->user()->customer->getOption('sender_id_verification') == 'yes')
                                        <div class="col-12">
                                            <p class="text-uppercase">{{ __('locale.labels.originator') }}</p>
<p class="form-label">{{ __('locale.labels.originator_description') }}</p>
                                        </div>

                                        @can('view_sender_id')
                                            <div class="col-md-6 col-12 customized_select2">
                                                <div class="mb-1">
                                                    <label for="sender_id" class="form-label">{{ __('locale.labels.sender_id') }}</label>
                                                    <div class="input-group">
                                                        <div class="input-group-text">
                                                            <div class="form-check">
                                                                <input type="radio" class="form-check-input sender_id" name="originator" checked value="sender_id" id="sender_id_check"/>
                                                                <label class="form-check-label" for="sender_id_check"></label>
                                                            </div>
                                                        </div>

                                                        <div style="width: 17rem">
                                                            <select class="form-select select2" id="sender_id" name="sender_id">
                                                                @foreach($sender_ids as $sender_id)
                                                                    <option value="{{$sender_id->sender_id}}"> {{ $sender_id->sender_id }} </option>
                                                                @endforeach
                                                            </select>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        @endcan
                                    @else
                                        @can('view_numbers')
                                            <div class="col-md-6 col-12 customized_select2">

                                                <div class="mb-1">
                                                    <label for="sender_id" class="form-label">{{ __('locale.labels.sender_id') }}</label>
                                                    <div class="input-group">
                                                        <div class="input-group-text">
                                                            <div class="form-check">
                                                                <input type="radio" class="form-check-input sender_id" name="originator" checked value="sender_id" id="sender_id_check"/>
                                                                <label class="form-check-label" for="sender_id_check"></label>
                                                            </div>
                                                        </div>

                                                        <div style="width: 17rem">
                                                            <input type="text" id="sender_id"
                                                                   class="form-control input_sender_id @error('sender_id') is-invalid @enderror"
                                                                   name="sender_id" autofocus>
                                                        </div>
                                                    </div>
                                                </div>

                                            </div>
                                        @else
                                            <div class="col-12">
                                                <div class="mb-1">
                                                    <label for="sender_id" class="form-label">{{__('locale.labels.sender_id')}}</label>
                                                    <input type="text" id="sender_id"
                                                           class="form-control @error('sender_id') is-invalid @enderror"
                                                           name="sender_id">
                                                    @error('sender_id')
                                                    <p><small class="text-danger">{{ $message }}</small></p>
                                                    @enderror
                                                </div>
                                            </div>
                                        @endcan
                                    @endif


                                    @can('view_numbers')
                                        <div class="col-md-6 col-12 customized_select2">
                                            <div class="mb-1">
                                                <label for="phone_number" class="form-label">{{ __('locale.menu.Phone Numbers') }}</label>
                                                <div class="input-group">
                                                    <div class="input-group-text">
                                                        <div class="form-check">
                                                            <input type="radio" class="form-check-input phone_number" value="phone_number" name="originator" id="phone_number_check"/>
                                                            <label class="form-check-label" for="phone_number_check"></label>
                                                        </div>
                                                    </div>
                                                    <div style="width: 17rem">
                                                        <select class="form-select select2" disabled id="phone_number" name="phone_number">
                                                            @foreach($phone_numbers as $number)
                                                                <option value="{{ $number->number }}"> {{ $number->number }} </option>
                                                            @endforeach
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    @endcan


                                    <div class="col-12">
                                        <div class="mb-1">
                                            <label class="sms_template form-label">{{__('locale.permission.sms_template')}}</label>
                                            <select class="form-select select2" id="sms_template">
                                                <option>{{ __('locale.labels.select_one') }}</option>
                                                @foreach($templates as $template)
                                                    <option value="{{$template->id}}">{{ $template->name }}</option>
                                                @endforeach

                                            </select>
                                        </div>
                                    </div>

                                    <div class="col-12">
                                        <div class="mb-1">
                                            <label for="message" class="required form-label">{{__('locale.labels.message')}}</label>
                                            <textarea class="form-control" name="message" rows="5" id="message"></textarea>
                                            <div class="d-flex justify-content-between">
                                                <small class="text-primary text-uppercase text-start" id="remaining">160 {{ __('locale.labels.characters_remaining') }}</small>
                                                <small class="text-primary text-uppercase text-end" id="messages">1 {{ __('locale.labels.message') }} (s)</small>
                                            </div>
                                            @error('message')
                                            <p><small class="text-danger">{{ $message }}</small></p>
                                            @enderror
                                        </div>
                                    </div>


                                    <div class="col-md-6 col-12">
                                        <div class="mb-1">
                                            <label class="required form-label" for="language">{{ __('locale.labels.language') }}</label>
                                            <select class="form-select" id="language" name="language" required>
                                                @foreach(\App\Helpers\Helper::voice_regions() as $key => $value)
                                                    <option value="{{$key}}" {{ $key == 'en-GB' ? 'selected': null }}> {{ $value }} </option>
                                                @endforeach
                                            </select>

                                            @error('language')
                                            <p><small class="text-danger">{{ $message }}</small></p>
                                            @enderror
                                        </div>
                                    </div>

                                    <div class="col-md-6 col-12">
                                        <div class="mb-1">
                                            <label class="form-label required" for="gender">{{ __('locale.labels.gender') }}</label>
                                            <select class="form-select" id="gender" name="gender">
                                                <option value="male"> {{ __('locale.labels.male') }}</option>
                                                <option value="female"> {{ __('locale.labels.female') }}</option>
                                            </select>
                                            @error('gender')
                                            <p><small class="text-danger">{{ $message }}</small></p>
                                            @enderror
                                        </div>
                                    </div>

                                </div>

                                <div class="row">
                                    <div class="col-12">
                                        <input type="hidden" value="voice" name="sms_type" id="sms_type">
                                        <button type="submit" class="btn btn-primary mr-1 mb-1"><i data-feather="send"></i> {{ __('locale.buttons.send') }}
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <!-- // Basic Vertical form layout section end -->

@endsection

@section('vendor-script')
    <!-- vendor files -->
    <script src="{{ asset(mix('vendors/js/forms/select/select2.full.min.js')) }}"></script>
@endsection

@section('page-script')
    <script src="{{ asset(mix('js/scripts/sms-counter.js')) }}"></script>
    <script>
        $(document).ready(function () {

            $(".sender_id").on("click", function () {
                $("#sender_id").prop("disabled", !this.checked);
                $("#phone_number").prop("disabled", this.checked);
            });

            $(".phone_number").on("click", function () {
                $("#phone_number").prop("disabled", !this.checked);
                $("#sender_id").prop("disabled", this.checked);
            });


            // Basic Select2 select
            $(".select2").each(function () {
                let $this = $(this);
                $this.wrap('<div class="position-relative"></div>');
                $this.select2({
                    // the following code is used to disable x-scrollbar when click in select input and
                    // take 100% width in responsive also
                    dropdownAutoWidth: true,
                    width: '100%',
                    dropdownParent: $this.parent(),
                });
            });

            // Basic Select2 select
            $("#language").each(function () {
                let $this = $(this);
                $this.wrap('<div class="position-relative"></div>');
                $this.select2({
                    // the following code is used to disable x-scrollbar when click in select input and
                    // take 100% width in responsive also
                    dropdownAutoWidth: true,
                    width: '100%',
                    dropdownParent: $this.parent(),
                });
            });

            let $remaining = $('#remaining'),
                $get_msg = $("#message"),
                $messages = $remaining.next(),
                firstInvalid = $('form').find('.is-invalid').eq(0);

            if (firstInvalid.length) {
                $('body, html').stop(true, true).animate({
                    'scrollTop': firstInvalid.offset().top - 200 + 'px'
                }, 200);
            }


            function isArabic(text) {
                let pattern = /[\u0600-\u06FF\u0750-\u077F]/;
                return pattern.test(text);
            }

            function get_character() {
                if ($get_msg[0].value !== null) {

                    let data = SmsCounter.count($get_msg[0].value, true);
                    $remaining.text(data.remaining + " {!! __('locale.labels.characters_remaining') !!}");
                    $messages.text(data.messages + " {!! __('locale.labels.message') !!}" + '(s)');

                }

            }

            $("#sms_template").on('change', function () {

                let template_id = $(this).val();

                $.ajax({
                    url: "{{ url('templates/show-data')}}" + '/' + template_id,
                    type: "POST",
                    data: {
                        _token: "{{csrf_token()}}"
                    },
                    cache: false,
                    success: function (data) {
                        if (data.status === 'success') {
                            const caretPos = $get_msg[0].selectionStart;
                            const textAreaTxt = $get_msg.val();
                            let txtToAdd = data.message;

                            $get_msg.val(textAreaTxt.substring(0, caretPos) + txtToAdd + textAreaTxt.substring(caretPos)).val().length;

                            get_character();

                        } else {
                            toastr['warning'](data.message, "{{ __('locale.labels.attention') }}", {
                                closeButton: true,
                                positionClass: 'toast-top-right',
                                progressBar: true,
                                newestOnTop: true,
                                rtl: isRtl
                            });
                        }
                    },
                    error: function (reject) {
                        if (reject.status === 422) {
                            let errors = reject.responseJSON.errors;
                            $.each(errors, function (key, value) {
                                toastr['warning'](value[0], "{{__('locale.labels.attention')}}", {
                                    closeButton: true,
                                    positionClass: 'toast-top-right',
                                    progressBar: true,
                                    newestOnTop: true,
                                    rtl: isRtl
                                });
                            });
                        } else {
                            toastr['warning'](reject.responseJSON.message, "{{__('locale.labels.attention')}}", {
                                closeButton: true,
                                positionClass: 'toast-top-right',
                                progressBar: true,
                                newestOnTop: true,
                                rtl: isRtl
                            });
                        }
                    }
                });
            });

            $get_msg.on('change keyup paste', get_character);
        });
    </script>
@endsection
