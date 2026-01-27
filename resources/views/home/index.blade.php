@extends('layouts.landing')

@section('title', 'Inicio | Vecipaya')

@section('content')

    @include('components.Home.banner')
    @include('components.Home.services')
    @include('components.Home.testimonials')
   {{--  @include('components.Home.gallery') --}}
    @include('components.Home.fun-facts')
    @include('components.Home.contact')
    @include('components.Home.blog')

@endsection