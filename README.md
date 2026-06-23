# SDG Frontend - Restructured

A comprehensive restructuring of the SDG Classification Analysis platform built with PHP, JavaScript, and modern web technologies. This project provides AI-powered analysis of research contributions to the United Nations Sustainable Development Goals (SDGs).

## 🛠️ Technology Stack

### External Services
- **ORCID API**: Researcher profile and publication data
- **Crossref API**: DOI resolution and metadata
- **Wizdam AI API**: SDG classification engine
- **CDN Integration**: External resource optimization

## 📋 Requirements

- **PHP**: Version 7.4 or higher
- **Extensions**: cURL, JSON, GD (optional for image processing)
- **Web Server**: Apache/Nginx with mod_rewrite
- **Browser**: Modern browsers supporting ES6+

## 🚀 Installation

1. **Clone or download** the project files to your web server
2. **Configure web server** to point to the `sdg/` directory
3. **Set permissions** for cache directory:
   ```bash
   chmod 755 cache/
   chmod 644 cache/*
   ```
4. **Update configuration** in `includes/config.php`:
   ```php
   $CONFIG = [
       'API_BASE_URL' => 'https://your-api-endpoint.com/api',
       'CACHE_TTL' => 3600,
       // ... other settings
   ];
   ```
5. **Test installation** by visiting the homepage

## 🔧 Configuration

### Basic Configuration (`includes/config.php`)
```php
// Site settings
define('SITE_NAME', 'SDGs Classification Analysis');
define('SITE_URL', 'https://your-domain.com');
define('VERSION', '5.1.8');

// API Configuration
$CONFIG = [
    'API_BASE_URL' => 'https://api.wizdam.ai/v1',
    'CACHE_TTL' => 3600,
    'MAX_WORKS_LIMIT' => 50,
    'TIMEOUT_CONNECT' => 5,
    'TIMEOUT_EXECUTE' => 10
];

// Optional: Analytics
define('GOOGLE_ANALYTICS_ID', 'GA_MEASUREMENT_ID');
```

### SDG Definitions (`includes/sdg_definitions.php`)
The SDG definitions array contains metadata for all 17 Sustainable Development Goals:
```php
$SDG_DEFINITIONS = [
    'SDG1' => [
        'title' => 'No Poverty',
        'color' => '#e5243b',
        'svg_url' => 'https://assets.sangia.org/img/SDGs_icon_SVG/Artboard_1.svg'
    ],
    // ... additional SDGs
];
```

## 📖 Usage

### Basic Analysis
1. **Visit the homepage** (`index.php` or `?page=home`)
2. **Enter an identifier**:
   - ORCID ID: `0000-0002-1825-0097`
   - DOI: `10.1038/nature12373`
3. **Click "Analyze"** and wait for results
4. **Explore results** with interactive charts and detailed breakdowns

### Response Format
```json
{
    "status": "success",
    "data": {
        "researcher_info": {
            "name": "John Doe",
            "orcid": "0000-0002-1825-0097",
            "affiliation": "University Example"
        },
        "sdg_summary": {
            "SDG3": {
                "work_count": 15,
                "avg_confidence": 0.85
            }
        },
        "works": [
            {
                "title": "Research Title",
                "doi": "10.1038/example",
                "sdg_classifications": ["SDG3", "SDG6"],
                "confidence_scores": {
                    "SDG3": 0.92,
                    "SDG6": 0.78
                }
            }
        ]
    }
}
```

### Special Thanks
- **UN SDG Team**: For providing SDG framework and guidelines
- **ORCID**: For researcher identification infrastructure
- **Crossref**: For DOI resolution services
