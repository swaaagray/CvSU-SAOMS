<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unauthorized Access - Academic Organization Management System</title>
    <link rel="icon" type="image/png" sizes="32x32" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link rel="apple-touch-icon" href="assets/img/CvSU-SAOMS_GREEN-WHITE.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: #f8f9fa;
            min-height: 100vh;
            display: flex;
            align-items: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .error-container {
            max-width: 600px;
            width: 100%;
            padding: 2rem;
        }
        .card {
            background: #ffffff;
            border: none;
            border-radius: 10px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        }
        .card-body {
            padding: 3rem;
            text-align: center;
        }
        .error-icon {
            width: 100px;
            height: 100px;
            margin: 0 auto 1.5rem;
            background: #dc3545;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 3rem;
        }
        .btn-primary {
            background: #212529;
            border: none;
            padding: 0.75rem 2rem;
            border-radius: 6px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            transition: all 0.3s ease;
        }
        .btn-primary:hover {
            background: #343a40;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(33, 37, 41, 0.15);
        }
        .btn-secondary {
            background: #6c757d;
            border: none;
            padding: 0.75rem 2rem;
            border-radius: 6px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            transition: all 0.3s ease;
        }
        .btn-secondary:hover {
            background: #5a6268;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(108, 117, 125, 0.15);
        }
        h1 {
            color: #212529;
            font-weight: 600;
            margin-bottom: 1rem;
        }
        p {
            color: #6c757d;
            margin-bottom: 2rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="error-container">
                <div class="card">
                    <div class="card-body">
                        <div class="error-icon">
                            <i class="fas fa-ban"></i>
                        </div>
                        <h1>403 - Unauthorized Access</h1>
                        <p>Sorry, you don't have permission to access this page. This area is restricted to authorized users only.</p>
                        
                        <div class="d-flex justify-content-center gap-3">
                            <a href="javascript:history.back()" class="btn btn-secondary">
                                <i class="fas fa-arrow-left me-2"></i>Go Back
                            </a>
                            <a href="dashboard.php" class="btn btn-primary">
                                <i class="fas fa-home me-2"></i>Dashboard
                            </a>
                        </div>
                        
                        <div class="mt-4">
                            <small class="text-muted">
                                If you believe this is an error, please contact your administrator.
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 