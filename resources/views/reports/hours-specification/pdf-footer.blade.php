<!DOCTYPE html>
<html lang="en">
<head>
    <style>
        .footer {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            display: flex;
            justify-content: space-between;
            font-size: 9px;
            font-family: 'Helvetica Neue', 'Helvetica', Helvetica, Arial, sans-serif;
            color: #a1a1aa;
            margin-left: 46px;
            margin-right: 46px;
            margin-bottom: 30px;
        }
    </style>
</head>
<body>
<div class="footer">
    <span>{{ $organizationName }} &middot; Hours specification</span>
    <span>Page <span class="pageNumber"></span> of <span class="totalPages"></span></span>
</div>
</body>
</html>
