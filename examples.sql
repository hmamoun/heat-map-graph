-- Example Queries for Heat Map Graph Plugin
-- Run this SQL script directly on your WordPress database to add example charts
-- Replace {prefix} with your actual WordPress table prefix (usually 'wp_')

-- Example 1: Bar Chart - Posts Published Per Month
INSERT INTO {prefix}heatmap_graphs (
    name,
    description,
    sql_query,
    row_field,
    col_field,
    value_field,
    color_min,
    color_max,
    is_enabled,
    chart_type,
    export_enabled,
    data_source_type,
    created_at,
    updated_at
) VALUES (
    'Posts Published Per Month (Bar Chart)',
    'Shows the number of posts published each month as a bar chart. Perfect for visualizing publishing trends over time.',
    'SELECT \n    DATE_FORMAT(post_date, ''%Y-%m'') AS row_label,\n    ''Posts Published'' AS col_label,\n    COUNT(*) AS cell_value\nFROM {prefix}posts\nWHERE post_type = ''post'' \n    AND post_status = ''publish''\nGROUP BY DATE_FORMAT(post_date, ''%Y-%m'')\nORDER BY row_label ASC',
    'row_label',
    'col_label',
    'cell_value',
    '#3b82f6',
    '#1e40af',
    1,
    'bar',
    1,
    'sql',
    NOW(),
    NOW()
);

-- Example 2: Pie Chart - Post Status Distribution
INSERT INTO {prefix}heatmap_graphs (
    name,
    description,
    sql_query,
    row_field,
    col_field,
    value_field,
    color_min,
    color_max,
    is_enabled,
    chart_type,
    export_enabled,
    data_source_type,
    created_at,
    updated_at
) VALUES (
    'Post Status Distribution (Pie Chart)',
    'Shows the distribution of posts by status (published, draft, etc.) as a pie chart. Great for understanding content workflow.',
    'SELECT \n    post_status AS row_label,\n    ''Status'' AS col_label,\n    COUNT(*) AS cell_value\nFROM {prefix}posts\nWHERE post_type = ''post''\nGROUP BY post_status\nORDER BY cell_value DESC',
    'row_label',
    'col_label',
    'cell_value',
    '#3b82f6',
    '#1e40af',
    1,
    'pie',
    1,
    'sql',
    NOW(),
    NOW()
);

-- Example 3: Line Chart - Posts Over Time by Post Type
INSERT INTO {prefix}heatmap_graphs (
    name,
    description,
    sql_query,
    row_field,
    col_field,
    value_field,
    color_min,
    color_max,
    is_enabled,
    chart_type,
    export_enabled,
    data_source_type,
    created_at,
    updated_at
) VALUES (
    'Posts Over Time by Post Type (Line Chart)',
    'Shows posts published per month, with separate lines for each post type. Ideal for comparing publishing trends across different content types.',
    'SELECT \n    DATE_FORMAT(post_date, ''%Y-%m'') AS row_label,\n    post_type AS col_label,\n    COUNT(*) AS cell_value\nFROM {prefix}posts\nWHERE post_status = ''publish''\nGROUP BY DATE_FORMAT(post_date, ''%Y-%m''), post_type\nORDER BY row_label ASC, col_label ASC',
    'row_label',
    'col_label',
    'cell_value',
    '#3b82f6',
    '#1e40af',
    1,
    'line',
    1,
    'sql',
    NOW(),
    NOW()
);

-- Example 4: Heat Map - Posts by Category and Month
INSERT INTO {prefix}heatmap_graphs (
    name,
    description,
    sql_query,
    row_field,
    col_field,
    value_field,
    color_min,
    color_max,
    is_enabled,
    chart_type,
    export_enabled,
    data_source_type,
    created_at,
    updated_at
) VALUES (
    'Posts by Category and Month (Heat Map)',
    'Shows post distribution across categories and months as a heat map. Darker colors indicate more posts. Perfect for identifying content patterns.',
    'SELECT \n    t.name AS row_label,\n    DATE_FORMAT(p.post_date, ''%Y-%m'') AS col_label,\n    COUNT(p.ID) AS cell_value\nFROM {prefix}posts p\nINNER JOIN {prefix}term_relationships tr ON tr.object_id = p.ID\nINNER JOIN {prefix}term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = ''category''\nINNER JOIN {prefix}terms t ON t.term_id = tt.term_id\nWHERE p.post_type = ''post'' \n    AND p.post_status = ''publish''\nGROUP BY t.term_id, t.name, DATE_FORMAT(p.post_date, ''%Y-%m'')\nORDER BY row_label ASC, col_label ASC',
    'row_label',
    'col_label',
    'cell_value',
    '#f0f9e8',
    '#084081',
    1,
    'heatmap',
    1,
    'sql',
    NOW(),
    NOW()
);

-- Example 5: API Data Source - Posts by User (JSONPlaceholder API)
-- This example demonstrates using an external API as a data source (Premium feature)
INSERT INTO wp_heatmap_graphs (
    name,
    description,
    sql_query,
    row_field,
    col_field,
    value_field,
    color_min,
    color_max,
    is_enabled,
    chart_type,
    export_enabled,
    data_source_type,
    external_config,
    created_at,
    updated_at
) VALUES (
    'Posts by User (API Example)',
    'Example chart using JSONPlaceholder API to show posts by user. Demonstrates external API data source feature.',
    '', -- SQL query not used for API sources
    'userId',
    'id',
    'id',
    '#3b82f6',
    '#1e40af',
    1,
    'bar',
    1,
    'api',
    '{"url":"https://jsonplaceholder.typicode.com/posts","auth_type":"none","auth_token":""}',
    NOW(),
    NOW()
);

-- Example 6: API Data Source - Canada Open Data (CKAN API)
-- This example demonstrates using Canada Open Data API (CKAN datastore_search)
-- Note: This requires Premium features to be enabled
-- The resource_id may need to be updated if the dataset changes
INSERT INTO wp_heatmap_graphs (
    name,
    description,
    sql_query,
    row_field,
    col_field,
    value_field,
    color_min,
    color_max,
    is_enabled,
    chart_type,
    export_enabled,
    data_source_type,
    external_config,
    slicers_config,
    created_at,
    updated_at
) VALUES (
    'Canada Open Data - Employee Count by Year and Universe',
    'Example chart using Canada Open Data API (CKAN datastore_search). Shows number of employees by year and universe type (APC/OD). Demonstrates querying government datasets via API with slicers.',
    '', -- SQL query not used for API sources
    'Annee',
    'Univers',
    'Nombre des employes',
    '#3b82f6',
    '#1e40af',
    1,
    'bar',
    1,
    'api',
    '{"url":"https://open.canada.ca/data/en/api/3/action/datastore_search?resource_id=d8e4906d-54a5-4d4b-8fca-9a7dd1bfa72e&limit=100","auth_type":"none","auth_token":""}',
    '{"enabled":true,"filter_rows":true,"filter_cols":true,"filter_values":true}',
    NOW(),
    NOW()
);

-- Note: After running this script, replace wp_ with your actual WordPress table prefix
-- For example, if your prefix is 'wp_', replace wp_ with 'wp_' throughout this file
-- You can find your table prefix in wp-config.php

