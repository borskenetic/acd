@extends('layouts.main')

@section('styles')
    <link rel="stylesheet" href="{{ asset('css/books/index.css') }}">
    <style>
        .faq-header h2,
        .faq-subtitle,
        .faq-item {
            font-family: var(--brand-font-family, 'Inter', sans-serif);
        }
        .faq-header h2 {
            font-size: 2rem;
            font-weight: 700;
            letter-spacing: -0.02em;
        }
    </style>
@endsection

@section('content')
    <section class="faq-section">
        <div class="faq-container">
            <div class="faq-header">
                <h2>Frequently Asked Questions</h2>
            </div>

            <h3 class="faq-subtitle">Getting Started</h3>

            <div class="faq-list">
                <div class="faq-item">
                    <p>
                        <a class="faq-toggle" data-bs-toggle="collapse" href="#registerCollapse" role="button"
                            aria-expanded="false" aria-controls="registerCollapse">
                            <strong>How can I register?</strong>
                        </a>
                    </p>
                    <div class="collapse" id="registerCollapse">
                        <div class="faq-video mt-2">
                            <video width="100%" controls playsinline>
                                <source src="{{ asset('videos/how_to_register.mp4') }}" type="video/mp4">
                                Your browser does not support the video tag.
                            </video>
                        </div>
                    </div>
                </div>

                <div class="faq-item">
                    <p><strong>Who can register?</strong></p>
                    <p class="mb-0 mt-2 text-muted">
                        Students and employees can register online. After submitting the form, staff will review and approve your account.
                    </p>
                </div>

                <div class="faq-item">
                    <p><strong>Where do I sign in for attendance?</strong></p>
                    <p class="mb-0 mt-2">
                        <a href="{{ route('attendance.scan') }}" class="faq-toggle">Open the gate terminal</a>
                    </p>
                </div>

                @guest('web')
                <div class="faq-item">
                    <p><strong>Staff or admin sign-in</strong></p>
                    <p class="mb-0 mt-2">
                        <a href="{{ route('login') }}" class="faq-toggle">Sign in to the attendance portal</a>
                    </p>
                </div>
                @endguest

                <div class="faq-item">
                    <p><strong>Visiting the campus?</strong></p>
                    <p class="mb-0 mt-2">
                        <a href="{{ route('visitors.register') }}" class="faq-toggle">Register as a visitor and get your QR pass</a>
                    </p>
                </div>

                @guest('web')
                <div class="faq-item">
                    <p><strong>Ready to register?</strong></p>
                    <p class="mb-0 mt-2">
                        <a href="{{ route('patron.register') }}" class="faq-toggle">Student or employee registration</a>
                    </p>
                </div>
                @endguest
            </div>
        </div>
    </section>
@endsection

@section('footer')
    <footer>
        <div class="a51-footer">
            <h4 style="color: white; font-size:15px">Pantas &copy; {{ date('Y') }}. All Rights Reserved.</h4>
        </div>
    </footer>
@endsection

