@extends('layouts/contentLayoutMaster')

@section('title', __('locale.labels.pay_payment'))

@section('content')
    <!-- Basic Vertical form layout section start -->
    <section id="basic-vertical-layouts">
        <div class="row match-height">
            <div class="col-md-6 col-12">
                <div class="card">
                    <div class="card-header"></div>
                    <div class="card-content">
                        <div class="card-body">
                            <form class="" role="form" method="post" action="{{ $post_url }}">
                                @csrf

                                <div class="mb-1">
                                    <label for="phone" class="required form-label">{{ __('locale.labels.phone') }}</label>
                                    <input type="text" id="phone" class="form-control @error('phone') is-invalid @enderror" value="{{ old('phone') }}" name="phone" required placeholder="{{__('locale.labels.required')}}" autofocus>
                                    @error('phone')
                                    <div class="invalid-feedback">
                                        {{ $message }}
                                    </div>
                                    @enderror
                                </div>


                                <div class="mb-1">
                                    <button type="submit" class="btn btn-primary">{{ __('locale.labels.pay_payment') }}</button>
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

@section('page-script')
    <!-- Page js files -->
    <script>

        $(document).ready(function () {
            "use strict"

            let firstInvalid = $('form').find('.is-invalid').eq(0);

            if (firstInvalid.length) {
                $('body, html').stop(true, true).animate({
                    'scrollTop': firstInvalid.offset().top - 200 + 'px'
                }, 200);
            }

        });
    </script>

@endsection
