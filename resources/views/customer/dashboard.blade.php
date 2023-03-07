@php use App\Library\Tool; @endphp
@extends('layouts/contentLayoutMaster')

@section('title', __('locale.menu.Dashboard'))

@section('vendor-style')
    {{-- vendor css files --}}
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/charts/apexcharts.css')) }}">

@endsection
@section('page-style')
    <link
            rel="stylesheet"
            href="https://unpkg.com/intro.js/minified/introjs.min.css"
    >
    {{-- Page css files --}}
    <link rel="stylesheet" href="{{ asset(mix('css/base/pages/dashboard-ecommerce.css')) }}">
    <link rel="stylesheet" href="{{ asset(mix('css/base/plugins/charts/chart-apex.css')) }}">
@endsection

@section('content')
    {{-- Dashboard Analytics Start --}}
    <section>

        <div class="row">

            <div class="col-lg-3 col-sm-6 col-12">
                <div id="contact-groups" class="card">
                    <div class="card-header">
                        @if(Auth::user()->customer->activeSubscription() != null)
                            <div>
                                <h2 class="fw-bolder mb-0"> {{ Auth::user()->customer->listsCount() != null ? Tool::format_number(Auth::user()->customer->listsCount()): 0 }}</h2>
                                <p class="card-text">{{ __('locale.contacts.contact_groups') }}</p>
                            </div>
                        @else
                            <div>
                                <h2 class="fw-bolder mb-0"> 0</h2>
                                <p class="card-text">{{ __('locale.contacts.contact_groups') }}</p>
                            </div>
                        @endif
                        <div class="avatar bg-light-primary p-50 m-0">
                            <div class="avatar-content">
                                <i data-feather="users" class="text-primary font-medium-5"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>


            <div class="col-lg-3 col-sm-6 col-12">
                <div id="contacts" class="card">
                    <div class="card-header">
                        @if(Auth::user()->customer->activeSubscription() != null)
                            <div
                            >
                                <h2 class="fw-bolder mb-0">{{ Auth::user()->customer->subscriberCounts() != null ? Tool::format_number(Auth::user()->customer->subscriberCounts()) : 0 }}</h2>
                                <p class="card-text">{{ __('locale.menu.Contacts') }}</p>
                            </div>
                        @else
                            <div>
                                <h2 class="fw-bolder mb-0">0</h2>
                                <p class="card-text">{{ __('locale.menu.Contacts') }}</p>
                            </div>
                        @endif

                        <div class="avatar bg-light-success p-50 m-0">
                            <div class="avatar-content">
                                <i data-feather="user" class="text-success font-medium-5"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>


            <div class="col-lg-3 col-sm-6 col-12">
                <div id="blacklist" class="card">
                    <div class="card-header">
                        <div>
                            <h2 class="fw-bolder mb-0">{{ Auth::user()->customer->blacklistCounts() }}</h2>
                            <p class="card-text">{{ __('locale.menu.Blacklist') }}</p>
                        </div>
                        <div class="avatar bg-light-danger p-50 m-0">
                            <div class="avatar-content">
                                <i data-feather="user-x" class="text-danger font-medium-5"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>


            <div class="col-lg-3 col-sm-6 col-12">
                <div id="sms-templates" class="card">
                    <div class="card-header">
                        <div>
                            <h2 class="fw-bolder mb-0">{{ Auth::user()->customer->smsTemplateCounts() }}</h2>
                            <p class="card-text">{{ __('locale.permission.sms_template') }}</p>
                        </div>
                        <div class="avatar bg-light-warning p-50 m-0">
                            <div class="avatar-content">
                                <i data-feather="inbox" class="text-warning font-medium-5"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-lg-8 col-sm-6 col-12">
                <div class="card">
                    <div class="card-header"></div>
                    <div class="card-body">
                        <h3 class="text-primary">{{ \App\Helpers\Helper::greetingMessage()}}</h3>
                        <p class="font-medium-2 mt-2">{{ __('locale.description.dashboard', ['brandname' => config('app.name')]) }}</p>
                    </div>
                </div>
            </div>

            <div class="col-lg-4 col-sm-6 col-12">
                <div class="card">
                    <div class="card-body">
                        <h3 class="text-primary">{{ __('locale.labels.current_plan')  }}</h3>
                        @if(Auth::user()->customer->activeSubscription() == null)
                            <h3 class="mt-1 text-danger">{{ __('locale.subscription.no_active_subscription') }}</h3>
                        @else
                            <p class="mb-2 mt-1 font-medium-2">{!! __('locale.subscription.you_are_currently_subscribed_to_plan',
                                        [
                                                'plan' => auth()->user()->customer->subscription->plan->name,
                                                'price' => Tool::format_price(auth()->user()->customer->subscription->plan->price, auth()->user()->customer->subscription->plan->currency->format),
                                                'remain' => Tool::formatHumanTime(auth()->user()->customer->subscription->current_period_ends_at),
                                                'end_at' => Tool::customerDateTime(auth()->user()->customer->subscription->current_period_ends_at)
                                        ]) !!}</p>
                        @endif
                        <a href="{{ route('customer.subscriptions.index') }}" class="btn btn-primary mt-3"><i
                                    data-feather="info"></i> {{ __('locale.labels.more_info') }}</a>
                    </div>
                </div>
            </div>

        </div>

        @if(Auth::user()->customer->activeSubscription() != null)
            <div class="row">

                <div class="col-lg-4 col-sm-6 col-12">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-end">
                            <h4 class="card-title text-uppercase">{{ __('locale.labels.sms_reports') }}</h4>
                        </div>
                        <div class="card-content">
                            <div class="card-body p-0">
                                <div id="sms-reports" class="my-2"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4 col-md-6 col-12">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-end">
                            <h4 class="card-title">{{ __('locale.contacts.contact_groups') }}</h4>
                        </div>

                        <div class="card-body p-0">
                            <div id="max-contact-list-chart" class="my-2"></div>

                            <div class="row border-top text-center mx-0">
                                <div class="col-6 border-end py-1">
                                    <p class="card-text text-muted mb-0">{{ __('locale.labels.total') }}</p>
                                    <h3 class="fw-bolder mb-0">
                                        @if(Auth::user()->customer->maxLists() != null)
                                            {{ (Auth::user()->customer->maxLists() == '∞' ) ? __('locale.labels.unlimited') : Tool::format_number(Auth::user()->customer->listsCount())}}</h3>
                                    @else
                                        0
                                    @endif
                                </div>
                                <div class="col-6 py-1">
                                    <p class="card-text text-muted mb-0">{{ __('locale.labels.remaining') }}</p>
                                    <h3 class="fw-bolder mb-0 contact_list">

                                        @if(Auth::user()->customer->maxLists() == '∞')
                                            {{ __('locale.labels.unlimited') }}
                                        @else
                                            @if(Auth::user()->customer->maxLists() > Auth::user()->customer->listsCount())
                                                {{ Tool::format_number(Auth::user()->customer->maxLists() - Auth::user()->customer->listsCount()) }}
                                            @else
                                                0
                                    @endif
                                    @endif

                                </div>
                            </div>
                        </div>

                    </div>
                </div>

                <div class="col-lg-4 col-md-6 col-12">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-end">
                            <h4 class="card-title">{{ __('locale.plans.max_contact') }}</h4>
                        </div>

                        <div class="card-body p-0">
                            <div id="max-contacts-chart" class="my-2"></div>
                            <div class="row border-top text-center mx-0">
                                <div class="col-6 border-end py-1">
                                    <p class="card-text text-muted mb-0">{{ __('locale.labels.total') }}</p>
                                    <h3 class="fw-bolder mb-0">
                                        {{ (Auth::user()->customer->maxSubscribers() == '∞') ? __('locale.labels.unlimited') : Tool::format_number(Auth::user()->customer->subscriberCounts()) }}
                                    </h3>
                                </div>
                                <div class="col-6 py-1">
                                    <p class="card-text text-muted mb-0">{{ __('locale.labels.remaining') }}</p>
                                    <h3 class="fw-bolder mb-0 remaining_contact">
                                        @if(Auth::user()->customer->maxSubscribers() == '∞')
                                            {{ __('locale.labels.unlimited') }}
                                        @else
                                            @if(Auth::user()->customer->maxSubscribers() > Auth::user()->customer->subscriberCounts())
                                                {{ Tool::format_number(Auth::user()->customer->maxSubscribers() - Auth::user()->customer->subscriberCounts()) }}
                                            @else
                                                0
                                            @endif
                                        @endif

                                    </h3>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        @endif

        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-end">
                        <h4 class="card-title text-uppercase">{{ __('locale.labels.outgoing_sms_history_of_current_month') }}</h4>
                    </div>
                    <div class="card-body pb-0">
                        <div id="sms-outbound"></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-end">
                        <h4 class="card-title text-uppercase">{{ __('locale.labels.incoming_sms_history_of_current_month') }}</h4>
                    </div>
                    <div class="card-content">
                        <div class="card-body pb-0">
                            <div id="sms-inbound"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        @can('developers')
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-end">
                            <h4 class="card-title text-uppercase">{{ __('locale.labels.api_sms_history_of_current_month') }}</h4>
                        </div>
                        <div class="card-content">
                            <div class="card-body pb-0">
                                <div id="sms-api"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @endcan

    </section>
    <!-- Dashboard Analytics end -->
@endsection


@section('vendor-script')
    {{--     Vendor js files --}}
    <script src="{{ asset(mix('vendors/js/charts/apexcharts.min.js')) }}"></script>
@endsection


@section('page-script')
    <script src="https://unpkg.com/intro.js/minified/intro.min.js"></script>


    <script>

        let tour = introJs();
        let tourHint = introJs("#navbar");
        $(window).on("load", function () {
            tour.setOptions(
                {
                    dontShowAgain: true,
                    dontShowAgainLabel: "{{__('locale.labels.dontShowAgainLabel') }}",
                    showProgress: true,
                    exitOnOverlayClick: false,
                    nextLabel: "{{__('locale.labels.nextLabel') }}",
                    prevLabel: "{{__('locale.labels.prevLabel') }}",
                    doneLabel: "{{__('locale.labels.gotocontactgroups') }}",
                    steps: [
                        {
                            title: 'Tutoriel',
                            intro: 'Profitez de ce tutoriel pour découvrir les fonctionnalités de votre compte.',
                        },
                        {
                            title: 'Groupe de contact',
                            intro: "Un groupe de contact est une liste d'abonnés à laquelle vous pouvez envoyer des messages. Dans cette section, vous pouvez créer, afficher et gérer vos groupes de contact.",
                            element: document.querySelector('#contact-groups')
                        },
                        {
                            title: 'Contact',
                            intro: 'La section Contact vous permet de consulter et de gérer la liste des contacts de votre compte. Vous pouvez ajouter, modifier ou supprimer des contacts individuellement ou par groupe.',
                            element: document.querySelector('#contacts')
                        },
                        {
                            title: "Liste noire",
                            intro: "La liste noire permet de gérer les numéros de téléphone indésirables. Vous pouvez ajouter des numéros à la liste noire et ces numéros ne recevront plus de SMS de votre part.",
                            element: document.querySelector('#blacklist')
                        },
                        {
                            title: 'Modèles SMS',
                            intro: 'Vous pouvez créer des modèles de SMS réutilisables pour faciliter l\'envoi de messages. Pour créer un modèle, cliquez sur "Créer un modèle", donnez un nom à votre modèle et écrivez votre message. Vous pouvez utiliser les variables disponibles pour personnaliser le message selon les informations de chaque contact.',
                            element: document.querySelector('#sms-templates')
                        },
                        {
                            title: 'Balance SMS',
                            intro: 'Cette section vous permet de consulter le solde de SMS disponible sur votre compte Geex SMS.',
                            element: document.querySelector('.show-balance')
                        },
                        {
                            title: 'Profile utilisateur',
                            intro: 'Dans cette section, vous pouvez voir les informations sur votre compte utilisateur, y compris votre nom et votre adresse e-mail. Vous pouvez également mettre à jour votre mot de passe ici si nécessaire.',
                            element: document.querySelector('.dropdown-user')
                        },
                        {
                            title: 'Contacts',
                            intro: "Cette section affiche la liste des contacts que vous avez créés. Pour gérer vos contacts, veuillez cliquer sur le bouton ci-dessous qui vous redirigera vers la page 'Groupe de contacts'.",
                            element: document.querySelector('#sidebar-contacts')
                        }
                    ]

                }
            );
            tour.onexit(() => {
                window.localStorage.setItem("tour-dashboard", true);
            });
            tour.oncomplete(() => {
                window.localStorage.setItem("tour-dashboard", true);
                window.location.href = "/contacts"
            });
            tour.start();
        });

        function percentage(partialValue, totalValue) {
            return (100 * partialValue) / totalValue;
        }
    </script>

    @if(Auth::user()->customer->activeSubscription() == null)

        <script>

            let CustomerSendingQuota = 0
            let CustomerMaxLists = 0;
            let CustomerMaxContacts = 0;

            $(window).on("load", function () {

                let $primary = '#7367F0';
                let $success = '#00db89';
                let $strok_color = '#b9c3cd';
                let $label_color = '#e7eef7';
                let $purple = '#df87f2';


                // outbound sms
                // -----------------------------

                let smsOutboundOptions = {
                    chart: {
                        height: 270,
                        toolbar: {show: false},
                        type: 'line',
                        dropShadow: {
                            enabled: true,
                            top: 20,
                            left: 2,
                            blur: 6,
                            opacity: 0.20
                        },
                    },
                    stroke: {
                        curve: 'smooth',
                        width: 4,
                    },
                    grid: {
                        borderColor: $label_color,
                    },
                    legend: {
                        show: false,
                    },
                    colors: [$purple],
                    fill: {
                        type: 'gradient',
                        gradient: {
                            shade: 'dark',
                            inverseColors: false,
                            gradientToColors: [$primary],
                            shadeIntensity: 1,
                            type: 'horizontal',
                            opacityFrom: 1,
                            opacityTo: 1,
                            stops: [0, 100, 100, 100]
                        },
                    },
                    markers: {
                        size: 0,
                        hover: {
                            size: 5
                        }
                    },
                    xaxis: {
                        labels: {
                            style: {
                                colors: $strok_color,
                            }
                        },
                        axisTicks: {
                            show: false,
                        },
                        categories: {!! $outgoing->xAxis() !!},
                        axisBorder: {
                            show: false,
                        },
                        tickPlacement: 'on',
                        type: 'string'
                    },
                    yaxis: {
                        tickAmount: 5,
                        labels: {
                            style: {
                                color: $strok_color,
                            },
                            formatter: function (val) {
                                return val > 999 ? (val / 1000).toFixed(1) + 'k' : val.toFixed(1);
                            }
                        }
                    },
                    tooltip: {
                        x: {show: false}
                    },
                    series: {!! $outgoing->dataSet() !!}

                }

                let smsOutbound = new ApexCharts(
                    document.querySelector("#sms-outbound"),
                    smsOutboundOptions
                );

                smsOutbound.render();


                // inbound sms
                // -----------------------------

                let smsInboundOptions = {
                    chart: {
                        height: 270,
                        toolbar: {show: false},
                        type: 'line',
                        dropShadow: {
                            enabled: true,
                            top: 20,
                            left: 2,
                            blur: 6,
                            opacity: 0.20
                        },
                    },
                    stroke: {
                        curve: 'smooth',
                        width: 4,
                    },
                    grid: {
                        borderColor: $label_color,
                    },
                    legend: {
                        show: false,
                    },
                    colors: [$purple],
                    fill: {
                        type: 'gradient',
                        gradient: {
                            shade: 'dark',
                            inverseColors: false,
                            gradientToColors: [$primary],
                            shadeIntensity: 1,
                            type: 'horizontal',
                            opacityFrom: 1,
                            opacityTo: 1,
                            stops: [0, 100, 100, 100]
                        },
                    },
                    markers: {
                        size: 0,
                        hover: {
                            size: 5
                        }
                    },
                    xaxis: {
                        labels: {
                            style: {
                                colors: $strok_color,
                            }
                        },
                        axisTicks: {
                            show: false,
                        },
                        categories: {!! $incoming->xAxis() !!},
                        axisBorder: {
                            show: false,
                        },
                        tickPlacement: 'on',
                        type: 'string'
                    },
                    yaxis: {
                        tickAmount: 5,
                        labels: {
                            style: {
                                color: $strok_color,
                            },
                            formatter: function (val) {
                                return val > 999 ? (val / 1000).toFixed(1) + 'k' : val.toFixed(1);
                            }
                        }
                    },
                    tooltip: {
                        x: {show: false}
                    },
                    series: {!! $incoming->dataSet() !!}

                }

                let smsInbound = new ApexCharts(
                    document.querySelector("#sms-inbound"),
                    smsInboundOptions
                );

                smsInbound.render();

                // API sms
                // -----------------------------

                let smsAPIOptions = {
                    chart: {
                        height: 270,
                        toolbar: {show: false},
                        type: 'line',
                        dropShadow: {
                            enabled: true,
                            top: 20,
                            left: 2,
                            blur: 6,
                            opacity: 0.20
                        },
                    },
                    stroke: {
                        curve: 'smooth',
                        width: 4,
                    },
                    grid: {
                        borderColor: $label_color,
                    },
                    legend: {
                        show: false,
                    },
                    colors: [$purple],
                    fill: {
                        type: 'gradient',
                        gradient: {
                            shade: 'dark',
                            inverseColors: false,
                            gradientToColors: [$primary],
                            shadeIntensity: 1,
                            type: 'horizontal',
                            opacityFrom: 1,
                            opacityTo: 1,
                            stops: [0, 100, 100, 100]
                        },
                    },
                    markers: {
                        size: 0,
                        hover: {
                            size: 5
                        }
                    },
                    xaxis: {
                        labels: {
                            style: {
                                colors: $strok_color,
                            }
                        },
                        axisTicks: {
                            show: false,
                        },
                        categories: {!! $api->xAxis() !!},
                        axisBorder: {
                            show: false,
                        },
                        tickPlacement: 'on',
                        type: 'string'
                    },
                    yaxis: {
                        tickAmount: 5,
                        labels: {
                            style: {
                                color: $strok_color,
                            },
                            formatter: function (val) {
                                return val > 999 ? (val / 1000).toFixed(1) + 'k' : val.toFixed(1);
                            }
                        }
                    },
                    tooltip: {
                        x: {show: false}
                    },
                    series: {!! $api->dataSet() !!}

                }

                let smsAPI = new ApexCharts(
                    document.querySelector("#sms-api"),
                    smsAPIOptions
                );

                smsAPI.render();

                // contact list  Chart
                // -----------------------------

                let contactListChartoptions = {
                    chart: {
                        height: 245,
                        type: 'radialBar',
                        sparkline: {
                            enabled: true,
                        },
                        dropShadow: {
                            enabled: true,
                            blur: 3,
                            left: 1,
                            top: 1,
                            opacity: 0.1
                        },
                    },
                    colors: [$success],
                    plotOptions: {
                        radialBar: {
                            offsetY: -10,
                            startAngle: -150,
                            endAngle: 150,
                            hollow: {
                                size: '77%'
                            },
                            track: {
                                background: $strok_color,
                                strokeWidth: '50%',
                            },
                            dataLabels: {
                                name: {
                                    show: false
                                },
                                value: {
                                    offsetY: 18,
                                    color: $strok_color,
                                    fontSize: '4rem'
                                }
                            }
                        }
                    },
                    fill: {
                        type: 'gradient',
                        gradient: {
                            shade: 'dark',
                            type: 'horizontal',
                            shadeIntensity: 0.5,
                            gradientToColors: ['#00b5b5'],
                            inverseColors: true,
                            opacityFrom: 1,
                            opacityTo: 1,
                            stops: [0, 100]
                        },
                    },
                    series: [parseFloat(CustomerMaxLists).toFixed(1)],
                    stroke: {
                        lineCap: 'round'
                    },
                    grid: {
                        padding: {
                            bottom: 30
                        }
                    }
                }

                let contactListChart = new ApexCharts(
                    document.querySelector("#max-contact-list-chart"),
                    contactListChartoptions
                );

                contactListChart.render();


                // contact  Chart
                // -----------------------------

                let contactChartoptions = {
                    chart: {
                        height: 245,
                        type: 'radialBar',
                        sparkline: {
                            enabled: true,
                        },
                        dropShadow: {
                            enabled: true,
                            blur: 3,
                            left: 1,
                            top: 1,
                            opacity: 0.1
                        },
                    },
                    colors: [$success],
                    plotOptions: {
                        radialBar: {
                            offsetY: -10,
                            startAngle: -150,
                            endAngle: 150,
                            hollow: {
                                size: '77%'
                            },
                            track: {
                                background: $strok_color,
                                strokeWidth: '50%',
                            },
                            dataLabels: {
                                name: {
                                    show: false
                                },
                                value: {
                                    offsetY: 18,
                                    color: $strok_color,
                                    fontSize: '4rem'
                                }
                            }
                        }
                    },
                    fill: {
                        type: 'gradient',
                        gradient: {
                            shade: 'dark',
                            type: 'horizontal',
                            shadeIntensity: 0.5,
                            gradientToColors: ['#00b5b5'],
                            inverseColors: true,
                            opacityFrom: 1,
                            opacityTo: 1,
                            stops: [0, 100]
                        },
                    },
                    series: [parseFloat(CustomerMaxContacts).toFixed(1)],
                    stroke: {
                        lineCap: 'round'
                    },
                    grid: {
                        padding: {
                            bottom: 30
                        }
                    }
                }

                let contactChart = new ApexCharts(
                    document.querySelector("#max-contacts-chart"),
                    contactChartoptions
                );

                contactChart.render();


            });

        </script>
    @else

        <script>


            let CustomerSendingQuota = "{{ Auth::user()->customer->getSendingQuota() }}";
            let CustomerSendingQuotaUsage = "{{  Auth::user()->customer->getSendingQuotaUsage() }}";

            if (CustomerSendingQuota === '-1') {
                CustomerSendingQuota = '0'
            } else {
                CustomerSendingQuota = percentage(CustomerSendingQuotaUsage, CustomerSendingQuota);
            }

            let remainingList = $(".contact_list").text().trim();
            let remainingContacts = $(".remaining_contact").text().trim();
            let CustomerMaxLists = "{{ Auth::user()->customer->getOption('list_max') }}";
            let ListCount = "{{ Auth::user()->customer->listsCount() }}";
            let MaxList = "{{ Auth::user()->customer->maxLists() }}";

            if (CustomerMaxLists === '-1') {
                CustomerMaxLists = '0'
            } else {
                if (CustomerMaxLists !== 0) {

                    if (remainingList === 0) {
                        CustomerMaxLists = 100;
                    } else {
                        CustomerMaxLists = percentage(ListCount, CustomerMaxLists);
                    }
                } else {
                    CustomerMaxLists = 0;
                }
            }


            let CustomerMaxContacts = "{{ Auth::user()->customer->getOption('subscriber_max') }}";
            let SubscriberCounts = "{{ Auth::user()->customer->subscriberCounts() }}";
            let MaxSubscribers = "{{ Auth::user()->customer->maxSubscribers() }}";

            if (CustomerMaxContacts === '-1') {
                CustomerMaxContacts = '0'
            } else {
                if (CustomerMaxContacts !== 0) {
                    if (remainingContacts === 0) {
                        CustomerMaxContacts = 100;
                    } else {
                        CustomerMaxContacts = percentage(SubscriberCounts, CustomerMaxContacts);
                    }
                } else {
                    CustomerMaxContacts = 0;
                }
            }

            $(window).on("load", function () {

                let $primary = '#7367F0';
                let $success = '#00db89';
                let $strok_color = '#b9c3cd';
                let $label_color = '#e7eef7';
                let $purple = '#df87f2';


                // outbound sms
                // -----------------------------

                let smsOutboundOptions = {
                    chart: {
                        height: 270,
                        toolbar: {show: false},
                        zoom: {enabled: false},
                        type: 'line',
                        dropShadow: {
                            enabled: true,
                            top: 18,
                            left: 2,
                            blur: 5,
                            opacity: 0.2
                        },
                        offsetX: -10
                    },
                    stroke: {
                        curve: 'smooth',
                        width: 4,
                    },
                    grid: {
                        borderColor: $label_color,
                        padding: {
                            top: -20,
                            bottom: 5,
                            left: 20
                        }
                    },
                    legend: {
                        show: false,
                    },
                    colors: [$purple],
                    fill: {
                        type: 'gradient',
                        gradient: {
                            shade: 'dark',
                            inverseColors: false,
                            gradientToColors: [$primary],
                            shadeIntensity: 1,
                            type: 'horizontal',
                            opacityFrom: 1,
                            opacityTo: 1,
                            stops: [0, 100, 100, 100]
                        },
                    },
                    markers: {
                        size: 0,
                        hover: {
                            size: 5
                        }
                    },
                    xaxis: {
                        labels: {
                            offsetY: 5,
                            style: {
                                colors: $strok_color,
                                fontSize: '0.857rem'
                            }
                        },
                        axisTicks: {
                            show: false,
                        },
                        categories: {!! $outgoing->xAxis() !!},
                        axisBorder: {
                            show: false,
                        },
                        tickPlacement: 'on',
                        type: 'string'
                    },
                    yaxis: {
                        tickAmount: 5,
                        labels: {
                            style: {
                                color: $strok_color,
                                fontSize: '0.857rem'
                            },
                            formatter: function (val) {
                                return val > 999 ? (val / 1000).toFixed(1) + 'k' : val.toFixed(1);
                            }
                        }
                    },
                    tooltip: {
                        x: {show: false}
                    },
                    series: {!! $outgoing->dataSet() !!}

                }

                let smsOutbound = new ApexCharts(
                    document.querySelector("#sms-outbound"),
                    smsOutboundOptions
                );

                smsOutbound.render();


                // inbound sms
                // -----------------------------

                let smsInboundOptions = {
                    chart: {
                        height: 270,
                        toolbar: {show: false},
                        zoom: {enabled: false},
                        type: 'line',
                        dropShadow: {
                            enabled: true,
                            top: 18,
                            left: 2,
                            blur: 5,
                            opacity: 0.2
                        },
                        offsetX: -10
                    },
                    stroke: {
                        curve: 'smooth',
                        width: 4,
                    },
                    grid: {
                        borderColor: $label_color,
                        padding: {
                            top: -20,
                            bottom: 5,
                            left: 20
                        }
                    },
                    legend: {
                        show: false,
                    },
                    colors: [$purple],
                    fill: {
                        type: 'gradient',
                        gradient: {
                            shade: 'dark',
                            inverseColors: false,
                            gradientToColors: [$primary],
                            shadeIntensity: 1,
                            type: 'horizontal',
                            opacityFrom: 1,
                            opacityTo: 1,
                            stops: [0, 100, 100, 100]
                        },
                    },
                    markers: {
                        size: 0,
                        hover: {
                            size: 5
                        }
                    },
                    xaxis: {
                        labels: {
                            offsetY: 5,
                            style: {
                                colors: $strok_color,
                                fontSize: '0.857rem'
                            }
                        },
                        axisTicks: {
                            show: false,
                        },
                        categories: {!! $incoming->xAxis() !!},
                        axisBorder: {
                            show: false,
                        },
                        tickPlacement: 'on',
                        type: 'string'
                    },
                    yaxis: {
                        tickAmount: 5,
                        labels: {
                            style: {
                                color: $strok_color,
                                fontSize: '0.857rem'
                            },
                            formatter: function (val) {
                                return val > 999 ? (val / 1000).toFixed(1) + 'k' : val.toFixed(1);
                            }
                        }
                    },
                    tooltip: {
                        x: {show: false}
                    },
                    series: {!! $incoming->dataSet() !!}

                }

                let smsInbound = new ApexCharts(
                    document.querySelector("#sms-inbound"),
                    smsInboundOptions
                );

                smsInbound.render();

                // API sms
                // -----------------------------

                let smsAPIOptions = {
                    chart: {
                        height: 270,
                        toolbar: {show: false},
                        zoom: {enabled: false},
                        type: 'line',
                        dropShadow: {
                            enabled: true,
                            top: 18,
                            left: 2,
                            blur: 5,
                            opacity: 0.2
                        },
                        offsetX: -10
                    },
                    stroke: {
                        curve: 'smooth',
                        width: 4,
                    },
                    grid: {
                        borderColor: $label_color,
                        padding: {
                            top: -20,
                            bottom: 5,
                            left: 20
                        }
                    },
                    legend: {
                        show: false,
                    },
                    colors: [$purple],
                    fill: {
                        type: 'gradient',
                        gradient: {
                            shade: 'dark',
                            inverseColors: false,
                            gradientToColors: [$primary],
                            shadeIntensity: 1,
                            type: 'horizontal',
                            opacityFrom: 1,
                            opacityTo: 1,
                            stops: [0, 100, 100, 100]
                        },
                    },
                    markers: {
                        size: 0,
                        hover: {
                            size: 5
                        }
                    },
                    xaxis: {
                        labels: {
                            offsetY: 5,
                            style: {
                                colors: $strok_color,
                                fontSize: '0.857rem'
                            }
                        },
                        axisTicks: {
                            show: false,
                        },
                        categories: {!! $api->xAxis() !!},
                        axisBorder: {
                            show: false,
                        },
                        tickPlacement: 'on',
                        type: 'string'
                    },
                    yaxis: {
                        tickAmount: 5,
                        labels: {
                            style: {
                                color: $strok_color,
                                fontSize: '0.857rem'
                            },
                            formatter: function (val) {
                                return val > 999 ? (val / 1000).toFixed(1) + 'k' : val.toFixed(1);
                            }
                        }
                    },
                    tooltip: {
                        x: {show: false}
                    },
                    series: {!! $api->dataSet() !!}

                }

                let smsAPI = new ApexCharts(
                    document.querySelector("#sms-api"),
                    smsAPIOptions
                );

                smsAPI.render();


                // contact list  Chart
                // -----------------------------

                let contactListChartoptions = {
                    chart: {
                        height: 245,
                        type: 'radialBar',
                        sparkline: {
                            enabled: true,
                        },
                        dropShadow: {
                            enabled: true,
                            blur: 3,
                            left: 1,
                            top: 1,
                            opacity: 0.1
                        },
                    },
                    colors: [$success],
                    plotOptions: {
                        radialBar: {
                            offsetY: -10,
                            startAngle: -150,
                            endAngle: 150,
                            hollow: {
                                size: '77%'
                            },
                            track: {
                                background: $strok_color,
                                strokeWidth: '50%',
                            },
                            dataLabels: {
                                name: {
                                    show: false
                                },
                                value: {
                                    offsetY: 18,
                                    color: $strok_color,
                                    fontSize: '4rem'
                                }
                            }
                        }
                    },
                    fill: {
                        type: 'gradient',
                        gradient: {
                            shade: 'dark',
                            type: 'horizontal',
                            shadeIntensity: 0.5,
                            gradientToColors: ['#00b5b5'],
                            inverseColors: true,
                            opacityFrom: 1,
                            opacityTo: 1,
                            stops: [0, 100]
                        },
                    },
                    series: [parseFloat(CustomerMaxLists).toFixed(1)],
                    stroke: {
                        lineCap: 'round'
                    },
                    grid: {
                        padding: {
                            bottom: 30
                        }
                    }
                }

                let contactListChart = new ApexCharts(
                    document.querySelector("#max-contact-list-chart"),
                    contactListChartoptions
                );

                contactListChart.render();


                // contact  Chart
                // -----------------------------

                let contactChartoptions = {
                    chart: {
                        height: 245,
                        type: 'radialBar',
                        sparkline: {
                            enabled: true,
                        },
                        dropShadow: {
                            enabled: true,
                            blur: 3,
                            left: 1,
                            top: 1,
                            opacity: 0.1
                        },
                    },
                    colors: [$success],
                    plotOptions: {
                        radialBar: {
                            offsetY: -10,
                            startAngle: -150,
                            endAngle: 150,
                            hollow: {
                                size: '77%'
                            },
                            track: {
                                background: $strok_color,
                                strokeWidth: '50%',
                            },
                            dataLabels: {
                                name: {
                                    show: false
                                },
                                value: {
                                    offsetY: 18,
                                    color: $strok_color,
                                    fontSize: '4rem'
                                }
                            }
                        }
                    },
                    fill: {
                        type: 'gradient',
                        gradient: {
                            shade: 'dark',
                            type: 'horizontal',
                            shadeIntensity: 0.5,
                            gradientToColors: ['#00b5b5'],
                            inverseColors: true,
                            opacityFrom: 1,
                            opacityTo: 1,
                            stops: [0, 100]
                        },
                    },
                    series: [parseFloat(CustomerMaxContacts).toFixed(1)],
                    stroke: {
                        lineCap: 'round'
                    },
                    grid: {
                        padding: {
                            bottom: 30
                        }
                    }
                }

                let contactChart = new ApexCharts(
                    document.querySelector("#max-contacts-chart"),
                    contactChartoptions
                );

                contactChart.render();


                // sms history Chart
                // -----------------------------

                let smsHistoryChartoptions = {
                    chart: {
                        type: 'pie',
                        height: 285,
                        toolbar: {
                            show: false
                        }
                    },
                    labels: ["{{ __('locale.labels.delivered') }}", "{{ __('locale.labels.failed') }}"],
                    series: {!! $sms_history->dataSet() !!},
                    dataLabels: {
                        enabled: false
                    },
                    legend: {show: false},
                    stroke: {
                        width: 4
                    },
                    colors: ['#7367F0', '#EA5455'],
                }

                let smsHistoryChart = new ApexCharts(
                    document.querySelector("#sms-reports"),
                    smsHistoryChartoptions
                );

                smsHistoryChart.render();

            });

        </script>

    @endif

@endsection
