@extends('layouts.site', ['minimal' => true])

@section('title', __('layout.errors.500.title', [], 'ar').' | '.__('layout.errors.500.title', [], 'en'))

@section('content')
    @include('errors.partials.bilingual', ['status' => 500])
@endsection
