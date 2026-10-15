@extends('layouts.site', ['minimal' => true])

@section('title', __('layout.errors.404.title', [], 'ar').' | '.__('layout.errors.404.title', [], 'en'))

@section('content')
    @include('errors.partials.bilingual', ['status' => 404])
@endsection
