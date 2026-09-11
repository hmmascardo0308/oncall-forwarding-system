<?php
session_start();
include 'config/config.php';
date_default_timezone_set('Asia/Manila');

// Check if user is already logged in
if (isset($_SESSION['user_id'])) {
    header("Location: access/home.php");
    exit;
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>OnCall Forwarding - Vehicle Rental System</title>
    <link rel="icon" type="image/png" href="images/oncall-forwarding.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Reset and Base Styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #333;
        }

        /* Navigation */
        .navbar {
            background: #ffffff;
            padding: 15px 0;
            position: fixed;
            width: 100%;
            top: 0;
            z-index: 1000;
            transition: all 0.3s ease;
        }

        .nav-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .logo img {
            height: 40px;
            width: auto;
        }

        .logo span {
            font-size: 20px;
            font-weight: 700;
            color: #1a3c6e;
        }

        .nav-menu {
            display: flex;
            list-style: none;
            gap: 30px;
            align-items: center;
        }

        .nav-menu a {
            text-decoration: none;
            color: #333;
            font-weight: 500;
            transition: color 0.3s;
        }

        .nav-menu a:hover {
            color: #1a3c6e;
        }

        .login-btn {
            background: #1a3c6e;
            color: #fff !important;
            padding: 8px 20px;
            border-radius: 25px;
            transition: background 0.3s;
        }

        .login-btn:hover {
            background: #2a5c9e !important;
        }

        .hamburger {
            display: none;
            flex-direction: column;
            cursor: pointer;
        }

        .hamburger span {
            width: 25px;
            height: 3px;
            background: #1a3c6e;
            margin: 3px 0;
            transition: 0.3s;
        }

        /* Hero Section */
        .hero {
            padding: 120px 0 80px;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        }

        .hero-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
            display: flex;
            align-items: center;
            gap: 50px;
        }

        .hero-content {
            flex: 1;
        }

        .hero-content h1 {
            font-size: 48px;
            color: #1a3c6e;
            margin-bottom: 20px;
            line-height: 1.2;
        }

        .hero-content p {
            font-size: 18px;
            color: #666;
            margin-bottom: 30px;
        }

        .hero-image {
            flex: 1;
            text-align: center;
        }

        .hero-image img {
            max-width: 100%;
            height: auto;
            max-height: 350px;
        }

        /* Fleet Section */
        .fleet {
            padding: 80px 0;
            background: #ffffff;
        }

        .section-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }

        .section-title {
            text-align: center;
            font-size: 36px;
            color: #1a3c6e;
            margin-bottom: 15px;
        }

        .section-subtitle {
            text-align: center;
            color: #666;
            font-size: 18px;
            margin-bottom: 40px;
        }

        .fleet-categories {
            display: flex;
            justify-content: center;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 40px;
        }

        .category-btn {
            padding: 10px 25px;
            border: 2px solid #1a3c6e;
            background: transparent;
            color: #1a3c6e;
            border-radius: 25px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
            font-size: 14px;
        }

        .category-btn:hover {
            background: #1a3c6e;
            color: #ffffff;
        }

        .category-btn.active {
            background: #1a3c6e;
            color: #ffffff;
        }

        .fleet-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 30px;
            margin-top: 20px;
        }

        /* Vehicle Card - RECTANGULAR BOX */
        .vehicle-card {
            background: #ffffff;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            display: flex;
            flex-direction: column;
        }

        .vehicle-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }

        .vehicle-image {
            width: 100%;
            height: 220px;
            background: #bfdfff;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            padding: 15px;
            border-bottom: 1px solid #eee;
        }

        .vehicle-image img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            transition: transform 0.3s ease;
        }

        .vehicle-card:hover .vehicle-image img {
            transform: scale(1.05);
        }

        .vehicle-details {
            padding: 20px;
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .vehicle-details h3 {
            font-size: 18px;
            font-weight: 600;
            color: #1a3c6e;
            margin: 0 0 5px 0;
        }

        .vehicle-type {
            color: #666;
            font-size: 14px;
            margin-bottom: 10px;
        }

        .vehicle-features {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            margin: 10px 0;
            padding: 10px 0;
            border-top: 1px solid #eee;
            border-bottom: 1px solid #eee;
        }

        .vehicle-features span {
            font-size: 13px;
            color: #555;
        }

        .vehicle-features i {
            color: #1a3c6e;
            margin-right: 5px;
        }

        .vehicle-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 15px;
        }

        .vehicle-price {
            font-size: 22px;
            font-weight: 700;
            color: #1a3c6e;
        }

        .vehicle-price span {
            font-size: 14px;
            font-weight: 400;
            color: #666;
        }

        .btn-rent {
            background: #1a3c6e;
            color: #fff;
            padding: 8px 20px;
            border-radius: 25px;
            text-decoration: none;
            font-weight: 500;
            transition: background 0.3s;
        }

        .btn-rent:hover {
            background: #2a5c9e;
        }

        .view-all {
            text-align: center;
            margin-top: 50px;
        }

        .btn-secondary {
            display: inline-block;
            background: transparent;
            color: #1a3c6e;
            padding: 12px 40px;
            border: 2px solid #1a3c6e;
            border-radius: 25px;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s;
        }

        .btn-secondary:hover {
            background: #1a3c6e;
            color: #fff;
        }

        /* Why Choose Us */
        .why-us {
            padding: 80px 0;
            background: #f8f9fa;
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 30px;
            margin-top: 40px;
        }

        .feature-card {
            background: #ffffff;
            padding: 30px;
            border-radius: 12px;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
            transition: transform 0.3s;
        }

        .feature-card:hover {
            transform: translateY(-5px);
        }

        .feature-icon {
            font-size: 40px;
            color: #1a3c6e;
            margin-bottom: 15px;
        }

        .feature-card h3 {
            color: #1a3c6e;
            margin-bottom: 10px;
        }

        .feature-card p {
            color: #666;
            font-size: 14px;
        }

        /* About Section */
        .about {
            padding: 80px 0;
            background: #ffffff;
        }

        .about-content {
            max-width: 800px;
            margin: 0 auto;
            text-align: center;
        }

        .about-content p {
            color: #666;
            margin-bottom: 20px;
            line-height: 1.8;
        }

        .stats {
            display: flex;
            justify-content: space-around;
            margin-top: 40px;
            gap: 20px;
            flex-wrap: wrap;
        }

        .stat-item {
            text-align: center;
        }

        .stat-number {
            display: block;
            font-size: 36px;
            font-weight: 700;
            color: #1a3c6e;
        }

        .stat-label {
            color: #666;
            font-size: 14px;
        }

        /* Contact Section */
        .contact {
            padding: 80px 0;
            background: #f8f9fa;
        }

        .contact-container {
            max-width: 800px;
            margin: 0 auto;
        }

        .contact-info {
            background: #ffffff;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
        }

        .contact-info h3 {
            color: #1a3c6e;
            margin-bottom: 15px;
        }

        .contact-info p {
            color: #666;
            margin-bottom: 25px;
        }

        .contact-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .contact-item {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #555;
        }

        .contact-item i {
            color: #1a3c6e;
            width: 20px;
        }

        /* Footer */
        .footer {
            background: #1a1a2e;
            color: #fff;
            padding: 60px 0 20px;
        }

        .footer-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }

        .footer-content {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 30px;
        }

        .footer-logo {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .footer-logo img {
            height: 40px;
            width: auto;
        }

        .footer-logo span {
            font-size: 18px;
            font-weight: 600;
        }

        .footer-text {
            color: #aaa;
            font-size: 14px;
        }

        .footer-links {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 30px;
            padding: 30px 0;
            border-top: 1px solid #333;
            border-bottom: 1px solid #333;
        }

        .footer-column h4 {
            color: #fff;
            margin-bottom: 15px;
        }

        .footer-column ul {
            list-style: none;
        }

        .footer-column ul li {
            margin-bottom: 8px;
        }

        .footer-column ul li a {
            color: #aaa;
            text-decoration: none;
            transition: color 0.3s;
        }

        .footer-column ul li a:hover {
            color: #fff;
        }

        .social-links {
            display: flex;
            gap: 15px;
        }

        .social-links a {
            color: #aaa;
            font-size: 20px;
            transition: color 0.3s;
        }

        .social-links a:hover {
            color: #fff;
        }

        .footer-bottom {
            text-align: center;
            padding-top: 20px;
            color: #aaa;
            font-size: 14px;
        }

        /* Responsive */
        @media (max-width: 968px) {
            .hero-container {
                flex-direction: column;
                text-align: center;
            }

            .hero-content h1 {
                font-size: 36px;
            }

            .nav-menu {
                display: none;
                position: absolute;
                top: 70px;
                left: 0;
                width: 100%;
                background: #fff;
                flex-direction: column;
                padding: 20px;
                box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
            }

            .nav-menu.active {
                display: flex;
            }

            .hamburger {
                display: flex;
            }

            .contact-details {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .vehicle-image {
                height: 180px;
            }

            .fleet-grid {
                grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
                gap: 20px;
            }

            .stats {
                flex-direction: column;
                gap: 20px;
            }

            .features-grid {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 480px) {
            .vehicle-image {
                height: 160px;
            }

            .fleet-grid {
                grid-template-columns: 1fr;
            }

            .features-grid {
                grid-template-columns: 1fr;
            }

            .hero-content h1 {
                font-size: 28px;
            }

            .section-title {
                font-size: 28px;
            }

            .vehicle-price {
                font-size: 18px;
            }

            .footer-links {
                grid-template-columns: 1fr 1fr;
            }
        }
    </style>
</head>
<body>

<!-- Navigation -->
<nav class="navbar">
    <div class="nav-container">
        <div class="logo">
            <img src="images/oncall-forwarding.png" alt="OnCall Forwarding Logo">
            <span>OnCall Vehicle Rental <span style="color: red; font-size: 13px; font-style: italic;">(VER. 39)</span></span>
        </div>
        <ul class="nav-menu">
            <li><a href="#home" class="active">Home</a></li>
            <li><a href="#fleet">Our Fleet</a></li>
            <!-- <li><a href="#rates">Rates</a></li> -->
            <li><a href="#about">About</a></li>
            <li><a href="#contact">Contact</a></li>
            <li><a href="login.php" class="login-btn">Login</a></li>
        </ul>
        <div class="hamburger">
            <span></span>
            <span></span>
            <span></span>
        </div>
    </div>
</nav>

<!-- Hero Section -->
<section id="home" class="hero">
    <div class="hero-container">
        <div class="hero-content">
            <h1>Drive Your Business Forward</h1>
            <p>Reliable trucks and trailers for every need. Flexible rental terms, competitive rates, and 24/7 road assistance.</p>
        </div>
        <div class="hero-image">
            <img src="images/company_logo.png" alt="Truck">
        </div>
    </div>
</section>

<!-- Our Fleet Section -->
<section id="fleet" class="fleet">
    <div class="section-container">
        <h2 class="section-title">Our Fleet</h2>
        <p class="section-subtitle">Choose from our wide range of well-maintained trucks and trailers</p>
        
        <div class="fleet-categories">
            <button class="category-btn active" data-category="all">All Vehicles</button>
            <button class="category-btn" data-category="trucks">Trucks</button>
            <button class="category-btn" data-category="trailers">Trailers</button>
        </div>

        <div class="fleet-grid">
            <!-- 10 Wheeler Truck -->
            <div class="vehicle-card" data-category="trucks">
                <div class="vehicle-image">
                    <img src="images/10_wheel.png" alt="10 Wheeler Truck">
                </div>
                <div class="vehicle-details">
                    <h3>10 Wheeler Truck</h3>
                    <p class="vehicle-type">Heavy Truck - 10 tons</p>
                    <div class="vehicle-footer">
                        <a href="login.php" class="btn-rent">Rent Now</a>
                    </div>
                </div>
            </div>

            <!-- Box Truck -->
            <div class="vehicle-card" data-category="trucks">
                <div class="vehicle-image">
                    <img src="images/box_truck.png" alt="Box Truck">
                </div>
                <div class="vehicle-details">
                    <h3>Box Truck</h3>
                    <p class="vehicle-type">Cargo Truck - 5 tons</p>
                   
                    <div class="vehicle-footer">
                        <a href="login.php" class="btn-rent">Rent Now</a>
                    </div>
                </div>
            </div>

            <!-- HOWO Truck -->
            <div class="vehicle-card" data-category="trucks">
                <div class="vehicle-image">
                    <img src="images/howo_truck.png" alt="HOWO Truck">
                </div>
                <div class="vehicle-details">
                    <h3>HOWO Truck</h3>
                    <p class="vehicle-type">Heavy Duty - 12 tons</p>
                   
                    <div class="vehicle-footer">
                        <a href="login.php" class="btn-rent">Rent Now</a>
                    </div>
                </div>
            </div>

            <!-- Wing Van -->
            <div class="vehicle-card" data-category="trucks">
                <div class="vehicle-image">
                    <img src="images/wing.png" alt="Wing Van">
                </div>
                <div class="vehicle-details">
                    <h3>Wing Van</h3>
                    <p class="vehicle-type">Delivery Van - 3.5 tons</p>
                    
                    <div class="vehicle-footer">
                        <a href="login.php" class="btn-rent">Rent Now</a>
                    </div>
                </div>
            </div>

            <!-- Trailer -->
            <div class="vehicle-card" data-category="trailers">
                <div class="vehicle-image">
                    <img src="images/trailer.png" alt="Trailer">
                </div>
                <div class="vehicle-details">
                    <h3>Trailer Truck</h3>
                    <p class="vehicle-type">Heavy Trailer - 20 tons</p>
                  
                    <div class="vehicle-footer">
                        <a href="login.php" class="btn-rent">Rent Now</a>
                    </div>
                </div>
            </div>

            <!-- Standard Truck -->
            <div class="vehicle-card" data-category="trucks">
                <div class="vehicle-image">
                    <img src="images/truck_2.png" alt="Standard Truck">
                </div>
                <div class="vehicle-details">
                    <h3>Standard Cargo Truck</h3>
                    <p class="vehicle-type">Cargo Truck - 8 tons</p>
                 
                    <div class="vehicle-footer">
                        <a href="login.php" class="btn-rent">Rent Now</a>
                    </div>
                </div>
            </div>
        </div>

        <div class="view-all">
            <a href="login.php" class="btn-secondary">View Full Fleet</a>
        </div>
    </div>
</section>

<!-- Why Choose Us -->
<section class="why-us">
    <div class="section-container">
        <h2 class="section-title">Why Choose Us</h2>
        <div class="features-grid">
            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fas fa-shield-alt"></i>
                </div>
                <h3>Fully Insured</h3>
                <p>All rentals include comprehensive insurance coverage for peace of mind.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fas fa-tools"></i>
                </div>
                <h3>24/7 Roadside Assistance</h3>
                <p>Round-the-clock support and emergency assistance anywhere in the country.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fas fa-calendar-check"></i>
                </div>
                <h3>Flexible Booking</h3>
                <p>Easy online booking with free cancellation and modification options.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fas fa-car-battery"></i>
                </div>
                <h3>Well-Maintained Fleet</h3>
                <p>Regular maintenance and safety checks on all vehicles.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fas fa-map-marker-alt"></i>
                </div>
                <h3>Multiple Locations</h3>
                <p>Convenient pickup and drop-off locations across Metro Manila.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fas fa-headset"></i>
                </div>
                <h3>Customer Support</h3>
                <p>Dedicated support team to assist you throughout your rental.</p>
            </div>
        </div>
    </div>
</section>

<!-- About Section -->
<section id="about" class="about">
    <div class="section-container">
        <div class="about-content">
            <h2 class="section-title">About OnCall Vehicle Rental</h2>
            <p>Since 2020, OnCall Vehicle Rental has been the trusted partner for businesses and individuals needing reliable transportation solutions. We understand that whether you're moving goods, transporting teams, or embarking on a journey, your vehicle needs to be dependable.</p>
            <p>Our fleet consists of modern, well-maintained trucks and trailers ranging from light cargo trucks to heavy-duty trailers. Every vehicle undergoes rigorous safety inspections and regular maintenance to ensure your safety and satisfaction.</p>
            <div class="stats">
                <div class="stat-item">
                    <span class="stat-number">5000+</span>
                    <span class="stat-label">Happy Clients</span>
                </div>
                <div class="stat-item">
                    <span class="stat-number">1000+</span>
                    <span class="stat-label">Vehicles</span>
                </div>
                <div class="stat-item">
                    <span class="stat-number">50k+</span>
                    <span class="stat-label">Rentals Completed</span>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Contact Section -->
<section id="contact" class="contact">
    <div class="section-container">
        <h2 class="section-title">Get In Touch</h2>
        <div class="contact-container">
            <div class="contact-info">
                <h3>Need help finding the right vehicle?</h3>
                <p>Our team is ready to assist you with your rental needs. Contact us for personalized recommendations and special rates for long-term rentals.</p>
                <div class="contact-details">
                    <div class="contact-item">
                        <i class="fas fa-phone"></i>
                        <span>420-0946 / 383-7076</span>
                    </div>
                    <div class="contact-item">
                        <i class="fas fa-envelope"></i>
                        <span>rentals@oncallvehicle.com</span>
                    </div>
                    <div class="contact-item">
                        <i class="fas fa-map-marker-alt"></i>
                        <span>Green Field Subd., Inayawan, Cebu City, Cebu, 6000</span>
                    </div>
                    <div class="contact-item">
                        <i class="fas fa-clock"></i>
                        <span>Mon-Sun: 6:00 AM - 10:00 PM</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Footer -->
<footer class="footer">
    <div class="footer-container">
        <div class="footer-content">
            <div class="footer-logo">
                <img src="images/oncall-forwarding.png" alt="OnCall Vehicle Rental">
                <span>OnCall Vehicle Rental</span>
            </div>
            <p class="footer-text">Your trusted partner for reliable vehicle rentals in the Philippines.</p>
        </div>
        <div class="footer-links">
            <div class="footer-column">
                <h4>Quick Links</h4>
                <ul>
                    <li><a href="#home">Home</a></li>
                    <li><a href="#fleet">Our Fleet</a></li>
                    <!-- <li><a href="#rates">Rates</a></li> -->
                    <li><a href="#about">About</a></li>
                    <li><a href="#contact">Contact</a></li>
                </ul>
            </div>
            <div class="footer-column">
                <h4>Vehicle Types</h4>
                <ul>
                    <li><a href="#">Trucks</a></li>
                    <li><a href="#">Trailers</a></li>
                </ul>
            </div>
            <div class="footer-column">
                <h4>Support</h4>
                <ul>
                    <li><a href="#">FAQ</a></li>
                    <li><a href="#">Terms & Conditions</a></li>
                    <li><a href="#">Privacy Policy</a></li>
                    <li><a href="#">Insurance Info</a></li>
                </ul>
            </div>
            <div class="footer-column">
                <h4>Follow Us</h4>
                <div class="social-links">
                    <a href="#"><i class="fab fa-facebook"></i></a>
                    <a href="#"><i class="fab fa-twitter"></i></a>
                    <a href="#"><i class="fab fa-instagram"></i></a>
                    <a href="#"><i class="fab fa-linkedin"></i></a>
                </div>
            </div>
        </div>
    </div>
    <div class="footer-bottom">
    <p>&copy; <span id="currentYear"></span> OnCall Vehicle Rental. All rights reserved.</p>
</div>

<script>
    document.getElementById('currentYear').textContent = new Date().getFullYear();
</script>
</footer>

<script>
// Mobile menu toggle
document.querySelector('.hamburger').addEventListener('click', function() {
    document.querySelector('.nav-menu').classList.toggle('active');
    this.classList.toggle('active');
});

// Smooth scrolling for anchor links
document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function (e) {
        e.preventDefault();
        const target = document.querySelector(this.getAttribute('href'));
        if (target) {
            target.scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });
            document.querySelector('.nav-menu').classList.remove('active');
            document.querySelector('.hamburger').classList.remove('active');
        }
    });
});

// Navbar background change on scroll
window.addEventListener('scroll', function() {
    const navbar = document.querySelector('.navbar');
    if (window.scrollY > 50) {
        navbar.style.background = '#ffffff';
        navbar.style.boxShadow = '0 2px 10px rgba(0,0,0,0.1)';
    } else {
        navbar.style.background = '#ffffff';
        navbar.style.boxShadow = 'none';
    }
});

// Fleet category filter
document.querySelectorAll('.category-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.category-btn').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        
        const category = this.dataset.category;
        
        document.querySelectorAll('.vehicle-card').forEach(card => {
            if (category === 'all' || card.dataset.category === category) {
                card.style.display = 'block';
            } else {
                card.style.display = 'none';
            }
        });
    });
});
</script>

</body>
</html>