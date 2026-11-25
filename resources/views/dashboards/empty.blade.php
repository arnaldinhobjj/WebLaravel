@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    <div class="page-head">
        <div>
            <h2 class="section-title">Dashboard</h2>
            <p class="page-sub">Ticket numbers, queues and activity at a glance.</p>
        </div>
    </div>

    <div class="empty-state">
        <p><strong>There are no dashboards yet.</strong></p>
        @can('dashboards.create')
            <p>Make your own: it starts with your tickets, and you can change every widget.</p>
            <p><a href="{{ route('dashboards.create') }}" class="btn btn-primary"><x-icon name="plus" :size="16" /> New dashboard</a></p>
        @else
            <p>Ask someone who can share dashboards to set one up for everyone.</p>
        @endcan
    </div>
@endsection
