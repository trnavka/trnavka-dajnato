{{--
  Template Name: Homepage
--}}

@extends('layouts.app')

@php
    $header_title = 'Super živá komunita';
    $header_description = 'Saleziánske dielo na Trnávke je komunitné centrum, v ktorom stovky ľudí denne trávia zmysluplný čas. Širokú ponuku služieb využívajú ľudia z Trnávky a širšieho okolia, bez ohľadu na ich vierovyznanie. Každý je vítaný.';
    $show_service_links = true;
	$dajnato_cta_title = 'Podporte nás pravidelným finančným darom';
	$dajnato_cta_button = 'Podporiť';
	$show_title_inside = false;
	get_the_excerpt();
@endphp

@section('content')
    @include('partials.campaign.header')

    <div class="container-fluid">
        <div class="wp-block-group wp-pattern-saleziani-call-to-action is-layout-flow wp-block-group-is-layout-flow has-light-blue-background-color">
            <div class="wp-block-group row is-layout-flow wp-block-group-is-layout-flow">
                <div class="wp-block-group col-sm-7 is-layout-flow wp-block-group-is-layout-flow">
                    <div class="wp-block-group content is-layout-flow wp-block-group-is-layout-flow">
                        <h2 class="wp-block-heading">Rekonštrukcia zázemia kostola a fary</h2>

                        <p>Začala sa 2. etapa rekonštrukcie: bezbariérové priestory, nové zázemie pre farnosť aj komunitu saleziánov a úspornejšia prevádzka. Pomôžte nám ju dokončiť do júna 2027.</p>

                        <div class="wp-block-buttons is-layout-flex wp-block-buttons-is-layout-flex">
                            <div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/dajnato/rekonstrukcia-zazemia-kostola-a-fary/">Chcem prispieť</a></div>
                        </div>
                    </div>
                </div>



                <div class="wp-block-group col-sm-5 is-layout-flow wp-block-group-is-layout-flow">
                    <figure class="wp-block-image size-full"><img decoding="async" src="@asset('images/rekonstrukcia-zazemia/homepage-banner.jpg')" alt="Vizualizácia zrekonštruovaného zázemia kostola a fary" /></figure>
                </div>
            </div>
        </div>
    </div>

    <div class="latest-posts">
        <div class="container-fluid section-header">
            <div class="content">
                <div class="row">
                    <div class="col-12">
                        <h2 class="wp-block-heading mb-3">Aktuality</h2>
                    </div>
                    <div class="col-lg-8">
                        <p class="lead">
                            Vyberáme pre vás novinky a správy, o ktoré sa chceme s vami podeliť.
                        </p>
                    </div>
                </div>
            </div>

            <div class="bootstrap-columns-container bootstrap-organization-columns">
                <div class="row">
                    @foreach($latest_posts as $post)
                        <div class="col-lg-4 col-md-6">
                            <div class="bootstrap-column-inner has-light-blue-background-color has-light-blue-background-color">
                                @if(!empty($post['thumbnail_url']))
                                    <figure class="wp-block-image size-full">
                                        <img src="{{$post['thumbnail_url']}}" alt="{{$post['post_title']}}">
                                    </figure>
                                @endif
                                <div class="wp-block-group content is-layout-flow wp-block-group-is-layout-flow">
                                    <div class="wp-block-group text is-layout-flow wp-block-group-is-layout-flow">
                                        <h3 class="wp-block-heading">{{$post['post_title']}}</h3>
                                        <p class="text">{!! $post['excerpt'] !!}</p>
                                    </div>
                                    <p class="link">
                                        <a class="stretched-link" href="/{{$post['post_name']}}">Čítať viac</a>
                                    </p>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    @include('partials.newsletter')

    @include('partials.campaign.services')

    <div class="footer">
        @include('partials.campaign.donate-widget')
        <div class="post-content-holder">

            @php the_content() @endphp
        </div>
    </div>
@endsection
