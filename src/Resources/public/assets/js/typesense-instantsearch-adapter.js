"use strict";

const utils = {
    _adaptHighlightTag(value, highlightPreTag, highlightPostTag) {
        return value
            .replace(new RegExp("<mark>", "g"), highlightPreTag || "<mark>")
            .replace(new RegExp("</mark>", "g"), highlightPostTag || "</mark>");
    },
    _adaptNumberOfPages() {
        // For union search, use union_request_params, otherwise use request_params
        const requestParams = this.typesenseResponse.union_request_params
            ? this.typesenseResponse.union_request_params[0]
            : this.typesenseResponse.request_params;

        const perPage = requestParams?.per_page || 10;
        const result = this.typesenseResponse.found / perPage;

        if (Number.isFinite(result)) {
            return Math.ceil(result);
        } else {
            return 1;
        }
    },
};
class Configuration {
    constructor(options = {}) {
        this.server = options.server || {
            nodes: [
                {
                    host: "localhost",
                    port: "8108",
                    path: "",
                    protocol: "http",
                },
            ],
        };

        this.server.cacheSearchResultsForSeconds = this.server.cacheSearchResultsForSeconds ?? 2 * 60;

        this.additionalSearchParameters = options.additionalSearchParameters ?? {};

        this.additionalSearchParameters.query_by =
            this.additionalSearchParameters.queryBy ?? this.additionalSearchParameters.query_by ?? "";

        this.additionalSearchParameters.preset =
            this.additionalSearchParameters.preset ?? this.additionalSearchParameters.preset ?? "";

        this.additionalSearchParameters.sort_by =
            this.additionalSearchParameters.sortBy ?? this.additionalSearchParameters.sort_by ?? "";

        this.additionalSearchParameters.highlight_full_fields =
            this.additionalSearchParameters.highlightFullFields ??
            this.additionalSearchParameters.highlight_full_fields ??
            this.additionalSearchParameters.query_by;

        this.geoLocationField = options.geoLocationField ?? "_geoloc";
        this.facetableFieldsWithSpecialCharacters = options.facetableFieldsWithSpecialCharacters ?? [];

        this.collectionSpecificSearchParameters = options.collectionSpecificSearchParameters ?? {};

        Object.keys(this.collectionSpecificSearchParameters).forEach((collection) => {
            const params = this.collectionSpecificSearchParameters[collection];
            params.query_by = params.queryBy ?? params.query_by;
            params.preset = params.preset ?? params.preset;
            params.sort_by = params.sortBy ?? params.sort_by;
            params.highlight_full_fields =
                params.highlightFullFields ??
                params.highlight_full_fields ??
                this.additionalSearchParameters.highlight_full_fields ??
                params.query_by;

            // Remove undefined values
            Object.keys(params).forEach((key) => (params[key] === undefined ? delete params[key] : {}));
        });

        this.renderingContent = options.renderingContent;
        this.flattenGroupedHits = options.flattenGroupedHits ?? true;
        this.facetByOptions = options.facetByOptions ?? {};
        this.filterByOptions = options.filterByOptions ?? {};
        this.sortByOptions = options.sortByOptions ?? {};
        this.collectionSpecificFacetByOptions = options.collectionSpecificFacetByOptions ?? {};
        this.collectionSpecificFilterByOptions = options.collectionSpecificFilterByOptions ?? {};
        this.collectionSpecificSortByOptions = options.collectionSpecificSortByOptions ?? {};
        this.union = options.union ?? false;
        // For Typesense v30+, use curation_tags. Set to true for older versions that use override_tags.
        this.useOverrideTags = options.useOverrideTags ?? false;
        // flips negative refinement encoding between AND/OR groups
        // AND groups become field:![a,b], OR groups become field:!a || field:!b
        this.flipNegativeRefinementOperator = options.flipNegativeRefinementOperator ?? false;
    }

    validate() {
        // Warn if camelCased parameters are used, suggest using snake_cased parameters instead
        if (
            this.additionalSearchParameters.queryBy ||
            Object.values(this.collectionSpecificSearchParameters).some((c) => c.queryBy)
        ) {
            console.warn(
                "[typesense-instantsearch-adapter] Please use snake_cased versions of parameters in additionalSearchParameters instead of camelCased parameters. For example: Use query_by instead of queryBy. camelCased parameters will be deprecated in a future version." +
                " We're making this change so that parameter names are identical to the ones sent to Typesense (which are all snake_cased), and to also keep the types for these parameters in sync with the types defined in typesense-js.",
            );
        }

        /*
         * Either additionalSearchParameters.query_by or additionalSearchParameters.preset needs to be set, or
         *   All collectionSpecificSearchParameters need to have query_by or preset
         *
         * */
        if (
            this.additionalSearchParameters.query_by.length === 0 &&
            this.additionalSearchParameters.preset.length === 0 &&
            (Object.keys(this.collectionSpecificSearchParameters).length === 0 ||
                Object.values(this.collectionSpecificSearchParameters).some(
                    (c) => (c.query_by || "").length === 0 && (c.preset || "").length === 0,
                ))
        ) {
            throw new Error(
                "[typesense-instantsearch-adapter] Missing parameter: One of additionalSearchParameters.query_by or additionalSearchParameters.preset needs to be set, or all collectionSpecificSearchParameters need to have either .query_by or .preset set.",
            );
        }
    }
}

class SearchRequestAdapter {
    static get INDEX_NAME_MATCHING_REGEX() {
        return new RegExp("^(.+?)(?=(/sort/(.*))|$)");
    }

    static get DEFAULT_FACET_FILTER_STRING_MATCHING_REGEX() {
        return new RegExp("(.*)((?!:).):(?!:)(.*)");
    }

    static get DEFAULT_NUMERIC_FILTER_STRING_MATCHING_REGEX() {
        return new RegExp("(.*?)(<=|>=|>|<|=)(.*)");
    }

    constructor(instantsearchRequests, typesenseClient, configuration) {
        this.instantsearchRequests = instantsearchRequests;
        this.typesenseClient = typesenseClient;
        this.configuration = configuration;
        this.additionalSearchParameters = configuration.additionalSearchParameters;
        this.collectionSpecificSearchParameters = configuration.collectionSpecificSearchParameters;
    }

    _shouldUseExactMatchForField(fieldName, collectionName) {
        if (
            this.configuration.collectionSpecificFilterByOptions?.[collectionName]?.[fieldName]?.exactMatch === false ||
            this.configuration.filterByOptions?.[fieldName]?.exactMatch === false
        ) {
            return false;
        } else {
            return true;
        }
    }

    _adaptFacetFilters(facetFilters, collectionName) {
        let adaptedResult = "";

        if (!facetFilters) {
            return adaptedResult;
        }

        /**
         * Need to transform:
         *  facetFilters = [["field1:value1", "field1:value2"], "field2:value3", "field2:value4"]
         *
         * Into this:
         *  field1:=[value1,value2] && field2:=value3 && field2:=value4
         *
         * Steps:
         *  - For each item in facetFilters
         *    - If item is array
         *      - OR values together.
         *      - Warn if field names are not the same
         *    - If item is string, convert to facet:=value format
         *  - Join strings by &&
         */

        const transformedTypesenseFilters = facetFilters.map((item) => {
            if (Array.isArray(item)) {
                // Need to transform:
                // facetFilters = ["field1:value1", "field1:value2", "facetN:valueN"]
                //
                // Into this:
                // intermediateFacetFilters = {
                //     "field1": ["value1", "value2"],
                //     "fieldN": ["valueN"]
                // }

                const intermediateFacetFilters = {};
                item.forEach((facetFilter) => {
                    const { fieldName, fieldValue } = this._parseFacetFilter(facetFilter);
                    intermediateFacetFilters[fieldName] = intermediateFacetFilters[fieldName] || [];
                    intermediateFacetFilters[fieldName].push(fieldValue);
                });

                if (Object.keys(intermediateFacetFilters).length > 1) {
                    console.error(
                        `[Typesense-Instantsearch-Adapter] Typesense does not support cross-field ORs at the moment. The adapter could not OR values between these fields: ${Object.keys(
                            intermediateFacetFilters,
                        ).join(",")}`,
                    );
                }

                // Pick first value from intermediateFacetFilters
                const fieldName = Object.keys(intermediateFacetFilters)[0];
                const fieldValues = intermediateFacetFilters[fieldName];

                // Need to transform:
                // intermediateFacetFilters = {
                //     "field1": ["value1", "value2"],
                // }
                //
                // Into this:
                // field1:=[value1,value2]

                // Partition values into included and excluded values
                const [excludedFieldValues, includedFieldValues] = fieldValues.reduce(
                    (result, fieldValue) => {
                        if (fieldValue.startsWith("-") && !this._isNumber(fieldValue)) {
                            result[0].push(fieldValue.substring(1));
                        } else {
                            result[1].push(fieldValue);
                        }
                        return result;
                    },
                    [[], []],
                );

                const typesenseFilterStringComponents = [];
                if (includedFieldValues.length > 0) {
                    const operator = this._shouldUseExactMatchForField(fieldName, collectionName) ? ":=" : ":";
                    typesenseFilterStringComponents.push(
                        `${fieldName}${operator}[${includedFieldValues.map((v) => this._escapeFacetValue(v)).join(",")}]`,
                    );
                }
                if (excludedFieldValues.length > 0) {
                    const operator = this._shouldUseExactMatchForField(fieldName, collectionName) ? ":!=" : ":!";
                    if (this.configuration.flipNegativeRefinementOperator) {
                        typesenseFilterStringComponents.push(
                            `(${excludedFieldValues.map((v) => `${fieldName}${operator}${this._escapeFacetValue(v)}`).join(" || ")})`,
                        );
                    } else {
                        typesenseFilterStringComponents.push(
                            `${fieldName}${operator}[${excludedFieldValues.map((v) => this._escapeFacetValue(v)).join(",")}]`,
                        );
                    }
                }

                const typesenseFilterString = typesenseFilterStringComponents.filter((f) => f).join(" && ");

                return typesenseFilterString;
            } else {
                // Need to transform:
                //  fieldName:fieldValue
                // Into
                //  fieldName:=fieldValue

                const { fieldName, fieldValue } = this._parseFacetFilter(item);
                let typesenseFilterString;
                if (fieldValue.startsWith("-") && !this._isNumber(fieldValue)) {
                    const operator = this._shouldUseExactMatchForField(fieldName, collectionName) ? ":!=" : ":!";
                    typesenseFilterString = `${fieldName}${operator}[${this._escapeFacetValue(fieldValue.substring(1))}]`;
                } else {
                    const operator = this._shouldUseExactMatchForField(fieldName, collectionName) ? ":=" : ":";
                    typesenseFilterString = `${fieldName}${operator}[${this._escapeFacetValue(fieldValue)}]`;
                }

                return typesenseFilterString;
            }
        });

        adaptedResult = transformedTypesenseFilters.join(" && ");
        // console.log(`${JSON.stringify(facetFilters)} => ${adaptedResult}`);

        return adaptedResult;
    }

    _parseFacetFilter(facetFilter) {
        let filterStringMatchingRegex, facetFilterMatches, fieldName, fieldValue;

        // This is helpful when the filter looks like `facetName:with:colons:facetValue:with:colons` and the default regex above parses the filter as `facetName:with:colons:facetValue:with` and `colon`.
        // So if a facetValue can contain a colon, we ask users to pass in all possible facetable fields in `facetableFieldsWithSpecialCharacters` when instantiating the adapter, so we can explicitly match against that.
        if (this.configuration.facetableFieldsWithSpecialCharacters?.length > 0) {
            // escape any Regex special characters, source: https://developer.mozilla.org/en-US/docs/Web/JavaScript/Guide/Regular_Expressions#escaping
            const sanitizedFacetableFieldsWithSpecialCharacters = this.configuration.facetableFieldsWithSpecialCharacters
                .flat()
                .map((f) => f.replace(/[.*+?^${}()|[\]\\]/g, "\\$&"));
            filterStringMatchingRegex = new RegExp(`^(${sanitizedFacetableFieldsWithSpecialCharacters.join("|")}):(.*)$`);
            facetFilterMatches = facetFilter.match(filterStringMatchingRegex);

            if (facetFilterMatches != null) {
                fieldName = `${facetFilterMatches[1]}`;
                fieldValue = `${facetFilterMatches[2]}`;

                return {
                    fieldName,
                    fieldValue,
                };
            }
        }

        // If we haven't found any matches yet
        // Use the default filter parsing regex, which assumes that only facet names have colons, and not facet values
        filterStringMatchingRegex = this.constructor.DEFAULT_FACET_FILTER_STRING_MATCHING_REGEX;
        facetFilterMatches = facetFilter.match(filterStringMatchingRegex);

        // console.log(filterStringMatchingRegex);
        // console.log(facetFilter);
        // console.log(facetFilterMatches);

        if (facetFilterMatches == null) {
            console.error(
                `[Typesense-Instantsearch-Adapter] Parsing failed for a facet filter \`${facetFilter}\` with the Regex \`${filterStringMatchingRegex}\`. If you have field names with special characters, be sure to add them to a parameter called \`facetableFieldsWithSpecialCharacters\` when instantiating the adapter.`,
            );
        } else {
            fieldName = `${facetFilterMatches[1]}${facetFilterMatches[2]}`;
            fieldValue = `${facetFilterMatches[3]}`;
        }

        return {
            fieldName,
            fieldValue,
        };
    }

    _escapeFacetValue(value) {
        // Don't escape booleans, integers or floats
        if (typeof value === "boolean" || value === "true" || value === "false" || this._isNumber(value)) {
            return value;
        }
        return `\`${value}\``;
    }

    _isNumber(value) {
        return (
            Number.isInteger(value % 1) || // Mod 1 will automatically try converting string values to integer/float
            !!(value % 1)
        ); // Is Float
    }

    _adaptNumericFilters(numericFilters) {
        // Need to transform this:
        // ["field1<=634", "field1>=289", "field2<=5", "field3>=3"]
        // to:
        // "field1:=[634..289] && field2:<=5 && field3:>=3"
        let adaptedResult = "";

        if (!numericFilters) {
            return adaptedResult;
        }

        // Transform to intermediate structure:
        // {
        //   field1: {
        //     "<=": 634,
        //     ">=": 289
        //   },
        //   field2: {
        //     "<=": 5
        //   },
        //   field3: {
        //     ">=": 3
        //   }
        // };
        const filtersHash = {};
        numericFilters.forEach((filter) => {
            const { fieldName, operator, fieldValue } = this._parseNumericFilter(filter);
            filtersHash[fieldName] = filtersHash[fieldName] || {};
            filtersHash[fieldName][operator] = fieldValue;
        });

        // Transform that to:
        //  "field1:=[634..289] && field2:<=5 && field3:>=3"
        const adaptedFilters = [];
        Object.keys(filtersHash).forEach((field) => {
            if (filtersHash[field]["<="] != null && filtersHash[field][">="] != null) {
                adaptedFilters.push(`${field}:=[${filtersHash[field][">="]}..${filtersHash[field]["<="]}]`);
            } else if (filtersHash[field]["<="] != null) {
                adaptedFilters.push(`${field}:<=${filtersHash[field]["<="]}`);
            } else if (filtersHash[field][">="] != null) {
                adaptedFilters.push(`${field}:>=${filtersHash[field][">="]}`);
            } else if (filtersHash[field]["="] != null) {
                adaptedFilters.push(`${field}:=${filtersHash[field]["="]}`);
            } else {
                console.warn(
                    `[Typesense-Instantsearch-Adapter] Unsupported operator found ${JSON.stringify(filtersHash[field])}`,
                );
            }
        });

        adaptedResult = adaptedFilters.join(" && ");
        return adaptedResult;
    }

    _parseNumericFilter(numericFilter) {
        let filterStringMatchingRegex, numericFilterMatches;
        let fieldName, operator, fieldValue;

        // The following is helpful when the facetName has special characters like > and the default regex fails to parse it properly.
        // So we ask users to pass in facetable fields in `facetableFieldsWithSpecialCharactersWithSpecialCharacters` when instantiating the adapter, so we can explicitly match against that.
        if (this.configuration.facetableFieldsWithSpecialCharacters?.length > 0) {
            // escape any Regex special characters, source: https://developer.mozilla.org/en-US/docs/Web/JavaScript/Guide/Regular_Expressions#escaping
            const sanitizedFacetableFieldsWithSpecialCharacters = this.configuration.facetableFieldsWithSpecialCharacters.map(
                (f) => f.replace(/[.*+?^${}()|[\]\\]/g, "\\$&"),
            );
            filterStringMatchingRegex = new RegExp(
                `^(${sanitizedFacetableFieldsWithSpecialCharacters.join("|")})(<=|>=|>|<|=)(.*)$`,
            );

            numericFilterMatches = numericFilter.match(filterStringMatchingRegex);

            if (numericFilterMatches != null) {
                // If no matches are found or if the above didn't trigger, fall back to the default regex
                [, fieldName, operator, fieldValue] = numericFilterMatches;
                return {
                    fieldName,
                    operator,
                    fieldValue,
                };
            }
        }

        // If we haven't found any matches yet, fall back to the default regex
        filterStringMatchingRegex = this.constructor.DEFAULT_NUMERIC_FILTER_STRING_MATCHING_REGEX;
        numericFilterMatches = numericFilter.match(filterStringMatchingRegex);

        // console.log(filterStringMatchingRegex);
        // console.log(numericFilter);
        // console.log(numericFilterMatches);

        if (numericFilterMatches == null) {
            console.error(
                `[Typesense-Instantsearch-Adapter] Parsing failed for a numeric filter \`${numericFilter}\` with the Regex \`${filterStringMatchingRegex}\`. If you have field names with special characters, be sure to add them to a parameter called \`facetableFieldsWithSpecialCharacters\` when instantiating the adapter.`,
            );
        } else {
            [, fieldName, operator, fieldValue] = numericFilterMatches;
        }

        return {
            fieldName,
            operator,
            fieldValue,
        };
    }

    _adaptGeoFilter({ insideBoundingBox, aroundRadius, aroundLatLng, insidePolygon }) {
        // Give this parameter first priority if it exists, since
        if (insideBoundingBox) {
            let x1, y1, x2, y2;
            if (Array.isArray(insideBoundingBox)) {
                [x1, y1, x2, y2] = insideBoundingBox.flat();
            } else {
                [x1, y1, x2, y2] = insideBoundingBox.split(",");
            }
            return `${this.configuration.geoLocationField}:(${x1}, ${y1}, ${x1}, ${y2}, ${x2}, ${y2}, ${x2}, ${y1})`;
        }

        if (aroundLatLng || aroundRadius) {
            if (!aroundRadius || aroundRadius === "all") {
                throw new Error(
                    "[Typesense-Instantsearch-Adapter] In Typesense, geo-filtering around a lat/lng also requires a numerical radius. " +
                    "So the `aroundRadius` parameter is required when `aroundLatLng` is used. " +
                    "If you intend to just geo-sort around a lat/long, you want to use the sortBy InstantSearch widget (or a virtual sortBy custom widget).",
                );
            }
            const adaptedAroundRadius = `${parseFloat(aroundRadius) / 1000} km`; // aroundRadius is in meters
            return `${this.configuration.geoLocationField}:(${aroundLatLng}, ${adaptedAroundRadius})`;
        }

        if (insidePolygon) {
            let coordinates = insidePolygon;
            if (Array.isArray(insidePolygon)) {
                coordinates = insidePolygon.flat().join(",");
            }
            return `${this.configuration.geoLocationField}:(${coordinates})`;
        }
    }

    _splitByTopLevelAnd(filterBy) {
        const clauses = [];
        let currentClause = "";
        let backtickOpen = false;

        for (let i = 0; i < filterBy.length; i += 1) {
            const char = filterBy[i];
            const nextChar = filterBy[i + 1];

            if (char === "`") {
                backtickOpen = !backtickOpen;
            }

            if (!backtickOpen && char === "&" && nextChar === "&") {
                if (currentClause.trim() !== "") {
                    clauses.push(currentClause.trim());
                }
                currentClause = "";
                i += 1;
                continue;
            }

            currentClause += char;
        }

        if (currentClause.trim() !== "") {
            clauses.push(currentClause.trim());
        }

        return clauses;
    }

    _splitListValues(valuesString) {
        const values = [];
        let currentValue = "";
        let backtickOpen = false;

        for (let i = 0; i < valuesString.length; i += 1) {
            const char = valuesString[i];
            if (char === "`") {
                backtickOpen = !backtickOpen;
            }

            if (!backtickOpen && char === ",") {
                if (currentValue.trim() !== "") {
                    values.push(currentValue.trim());
                }
                currentValue = "";
                continue;
            }

            currentValue += char;
        }

        if (currentValue.trim() !== "") {
            values.push(currentValue.trim());
        }

        return values;
    }

    _parseListClause(clause) {
        const trimmedClause = clause.trim();
        const openBracketIndex = trimmedClause.indexOf("[");
        const closeBracketIndex = trimmedClause.lastIndexOf("]");

        if (openBracketIndex === -1 || closeBracketIndex === -1 || closeBracketIndex !== trimmedClause.length - 1) {
            return null;
        }

        const leftSide = trimmedClause.slice(0, openBracketIndex).trim();
        const valuesString = trimmedClause.slice(openBracketIndex + 1, closeBracketIndex);

        const operators = [":!=", ":=", ":!", ":"];
        const operator = operators.find((candidateOperator) => leftSide.endsWith(candidateOperator));
        if (!operator) {
            return null;
        }

        const fieldName = leftSide.slice(0, leftSide.length - operator.length).trim();
        if (!fieldName) {
            return null;
        }

        return {
            fieldName,
            operator,
            values: this._splitListValues(valuesString),
        };
    }

    _mergeSameFieldExclusionListClauses(filterBy) {
        if (!filterBy) return filterBy;

        const clauses = this._splitByTopLevelAnd(filterBy);
        const groupedListClauses = {};
        const rebuiltClauses = [];

        clauses.forEach((clause, clauseIndex) => {
            const parsedClause = this._parseListClause(clause);
            if (!parsedClause) {
                rebuiltClauses.push({ clauseIndex, clause });
                return;
            }

            const normalizedOperator = parsedClause.operator === ":!" || parsedClause.operator === ":!=" ? "exclude" : null;
            if (normalizedOperator == null) {
                rebuiltClauses.push({ clauseIndex, clause });
                return;
            }

            const groupingKey = `${parsedClause.fieldName}|${normalizedOperator}`;
            if (!groupedListClauses[groupingKey]) {
                groupedListClauses[groupingKey] = {
                    clauseIndex,
                    fieldName: parsedClause.fieldName,
                    operator: parsedClause.operator,
                    normalizedOperator,
                    values: [],
                };
            }

            parsedClause.values.forEach((value) => {
                if (!groupedListClauses[groupingKey].values.includes(value)) {
                    groupedListClauses[groupingKey].values.push(value);
                }
            });
        });

        Object.values(groupedListClauses).forEach((group) => {
            rebuiltClauses.push({
                clauseIndex: group.clauseIndex,
                clause: `${group.fieldName}${group.operator}[${group.values.join(",")}]`,
            });
        });

        return rebuiltClauses
            .sort((left, right) => left.clauseIndex - right.clauseIndex)
            .map((entry) => entry.clause)
            .join(" && ");
    }

    _adaptFilters(instantsearchParams, collectionName) {
        const adaptedFilters = [];

        // `filters` can be used with the `Configure` widget
        // However the format needs to be in the Typesense filter_by format, instead of Algolia filter format.
        if (instantsearchParams.filters) {
            adaptedFilters.push(instantsearchParams.filters);
        }
        adaptedFilters.push(this._adaptFacetFilters(instantsearchParams.facetFilters, collectionName));
        adaptedFilters.push(this._adaptNumericFilters(instantsearchParams.numericFilters));
        adaptedFilters.push(this._adaptGeoFilter(instantsearchParams));

        const combinedFilter = adaptedFilters.filter((filter) => filter && filter !== "").join(" && ");
        if (this.configuration.flipNegativeRefinementOperator) {
            return this._mergeSameFieldExclusionListClauses(combinedFilter);
        }
        return combinedFilter;
    }

    _adaptIndexName(indexName) {
        return indexName.match(this.constructor.INDEX_NAME_MATCHING_REGEX)[1];
    }

    _adaptSortBy(indexName) {
        return indexName.match(this.constructor.INDEX_NAME_MATCHING_REGEX)[3];
    }

    _adaptFacetBy(facets, collectionName) {
        return [facets]
            .flat()
            .map((facet) => {
                if (this.configuration.collectionSpecificFacetByOptions?.[collectionName]?.[facet]) {
                    return `${facet}${this.configuration.collectionSpecificFacetByOptions[collectionName][facet]}`;
                } else if (this.configuration.facetByOptions[facet]) {
                    return `${facet}${this.configuration.facetByOptions[facet]}`;
                } else {
                    return facet;
                }
            })
            .join(",");
    }

    _adaptRulesContextsToOverrideTags(ruleContexts) {
        return ruleContexts.join(",");
    }

    _buildSearchParameters(instantsearchRequest) {
        const params = instantsearchRequest.params;
        const indexName = instantsearchRequest.indexName;
        let adaptedCollectionName = this._adaptIndexName(indexName);

        // Convert all common parameters to snake case
        const snakeCasedAdditionalSearchParameters = {};
        for (const [key, value] of Object.entries(this.additionalSearchParameters)) {
            snakeCasedAdditionalSearchParameters[this._camelToSnakeCase(key)] = value;
        }

        // Override, collection specific parameters
        if (this.collectionSpecificSearchParameters[adaptedCollectionName]) {
            for (const [key, value] of Object.entries(this.collectionSpecificSearchParameters[adaptedCollectionName])) {
                snakeCasedAdditionalSearchParameters[this._camelToSnakeCase(key)] = value;
            }
            delete snakeCasedAdditionalSearchParameters.collection;
            adaptedCollectionName = this.collectionSpecificSearchParameters[adaptedCollectionName].collection;
        }

        const typesenseSearchParams = Object.assign({}, snakeCasedAdditionalSearchParameters);

        const adaptedSortBy = this._adaptSortBy(indexName);

        Object.assign(typesenseSearchParams, {
            collection: adaptedCollectionName,
            q: params.query === "" || params.query === undefined ? "*" : params.query,
            facet_by:
                snakeCasedAdditionalSearchParameters.facet_by || this._adaptFacetBy(params.facets, adaptedCollectionName),
            filter_by: this._adaptFilters(params, adaptedCollectionName) || snakeCasedAdditionalSearchParameters.filter_by,
            sort_by: adaptedSortBy || snakeCasedAdditionalSearchParameters.sort_by,
            max_facet_values: params.maxValuesPerFacet,
            page: (params.page || 0) + 1,
        });

        if (params.hitsPerPage != null) {
            typesenseSearchParams.per_page = params.hitsPerPage;
        }

        if (params.facetQuery) {
            typesenseSearchParams.facet_query = `${params.facetName}:${params.facetQuery}`;
            typesenseSearchParams.per_page = 0;
        }

        if (params.ruleContexts && params.ruleContexts.length > 0) {
            const tagsParamName = this.configuration.useOverrideTags ? "override_tags" : "curation_tags";
            typesenseSearchParams[tagsParamName] = this._adaptRulesContextsToOverrideTags(params.ruleContexts);
        }

        // If a custom vector query is specified, set q=*
        if (params.typesenseVectorQuery) {
            typesenseSearchParams.vector_query = params.typesenseVectorQuery;
        }

        // Allow for conditional disabling of overrides, for particular sort orders
        let sortByOption =
            this.configuration.collectionSpecificSortByOptions?.[adaptedCollectionName]?.[typesenseSearchParams["sort_by"]] ||
            this.configuration.sortByOptions?.[typesenseSearchParams["sort_by"]];
        if (sortByOption?.["enable_overrides"] != null) {
            typesenseSearchParams["enable_overrides"] = sortByOption["enable_overrides"];
        }

        // console.log(params);
        // console.log(typesenseSearchParams);

        // Filter out empty or null values, so we don't accidentally override values set in presets
        // eslint-disable-next-line no-unused-vars
        return Object.fromEntries(Object.entries(typesenseSearchParams).filter(([_, v]) => v != null && v !== ""));
    }

    _camelToSnakeCase(str) {
        return str
            .split(/(?=[A-Z])/)
            .join("_")
            .toLowerCase();
    }

    async request() {
        // console.log(this.instantsearchRequests);

        let searches = this.instantsearchRequests.map((instantsearchRequest) =>
            this._buildSearchParameters(instantsearchRequest),
        );

        // If this is a conversational search, then move conversation related params to query params
        let commonParams = {};
        if (searches[0]?.conversation === true || searches[0]?.conversation === "true") {
            const { q, conversation, conversation_id, conversation_model_id } = searches[0];
            commonParams = { q, conversation, conversation_id, conversation_model_id };

            searches = searches.map((searchParams) => {
                // eslint-disable-next-line no-unused-vars
                const { q, conversation, conversation_id, conversation_model_id, ...modifiedSearchParams } = searchParams;
                return modifiedSearchParams;
            });
        }

        const searchRequest = { searches: searches };

        // Add union parameter if configured
        if (this.configuration.union) {
            searchRequest.union = this.configuration.union;
            ["page", "per_page", "offset", "limit", "limit_hits"].forEach((paramName) => {
                if (searches[0][paramName] != null) {
                    commonParams[paramName] = searches[0][paramName];
                }
            });
        }

        return this.typesenseClient.multiSearch.perform(searchRequest, commonParams);
    }
}

class SearchResponseAdapter {
    constructor(
        typesenseResponse,
        instantsearchRequest,
        configuration,
        allTypesenseResults = [],
        fullTypesenseResponse = {},
    ) {
        this.typesenseResponse = typesenseResponse;
        this.instantsearchRequest = instantsearchRequest;
        this.configuration = configuration;
        this.allTypesenseResults = allTypesenseResults;
        this.fullTypesenseResponse = fullTypesenseResponse;
    }

    _adaptGroupedHits(typesenseGroupedHits) {
        let adaptedResult = [];

        adaptedResult = typesenseGroupedHits.map((groupedHit) => {
            const adaptedHits = this._adaptHits(groupedHit.hits);
            adaptedHits.forEach((hit) => {
                hit["group_key"] = hit["_group_key"] = groupedHit.group_key;
                if (groupedHit.found) {
                    hit["_group_found"] = groupedHit.found;
                }
            });
            return adaptedHits;
        });

        // adaptedResult is now in the form of [[{}, {}], [{}, {}], ...]
        //  where each element in the outermost array corresponds to a group.

        if (this.configuration.flattenGroupedHits) {
            // We flatten it to [{}, {}, {}]
            adaptedResult = adaptedResult.flat();
        } else {
            // We flatten it to [{ ..., grouped_hits: [{}, {}] }, {}, {}]
            // We set the first element in the group as the hit, and then add a new key called grouped_hits with the other hits
            adaptedResult = adaptedResult.map((adaptedGroupedHit) => {
                return {
                    ...adaptedGroupedHit[0],
                    _grouped_hits: adaptedGroupedHit,
                };
            });
        }

        return adaptedResult;
    }

    _adaptHits(typesenseHits) {
        let adaptedResult = [];
        adaptedResult = typesenseHits.map((typesenseHit) => {
            const adaptedHit = {
                ...typesenseHit.document,
            };
            adaptedHit.objectID = typesenseHit.document.id;
            adaptedHit._snippetResult = this._adaptHighlightResult(typesenseHit, "snippet");
            adaptedHit._highlightResult = this._adaptHighlightResult(typesenseHit, "value");
            adaptedHit._rawTypesenseHit = typesenseHit;

            // We're adding `conversation` into each hit, since there doesn't seem to be any other way to pass this up to Instantsearch outside of hits
            if (this.fullTypesenseResponse.conversation) {
                adaptedHit._rawTypesenseConversation = this.fullTypesenseResponse.conversation;
            }

            // Add metadata fields to result, if a field with that name doesn't already exist
            [
                "text_match",
                "geo_distance_meters",
                "curated",
                "text_match_info",
                "hybrid_search_info",
                "vector_distance",
                "collection", // Union search specific
                "search_index", // Union search specific
            ].forEach((metadataField) => {
                if (Object.keys(typesenseHit).includes(metadataField) && !Object.keys(adaptedHit).includes(metadataField)) {
                    adaptedHit[metadataField] = typesenseHit[metadataField];
                }
            });

            const geoLocationValue = adaptedHit[this.configuration.geoLocationField];
            if (geoLocationValue) {
                adaptedHit._geoloc = {
                    lat: geoLocationValue[0],
                    lng: geoLocationValue[1],
                };
            }

            return adaptedHit;
        });
        return adaptedResult;
    }

    _adaptHighlightResult(typesenseHit, snippetOrValue) {
        const result = {};

        // If there is a highlight object available (as of v0.24.0),
        // And the highlight object uses the highlight format available in v0.24.0.rcn32 and above
        //  use that instead of the highlights array, since it supports highlights of nested fields
        if (typesenseHit.highlight != null && this.isHighlightPost0240RCN32Format(typesenseHit.highlight)) {
            this.adaptHighlightObject(typesenseHit, result, snippetOrValue);
        } else {
            this.adaptHighlightsArray(typesenseHit, result, snippetOrValue);
        }
        return result;
    }

    isHighlightPost0240RCN32Format(highlight) {
        return highlight.full == null && highlight.snippet == null;
    }

    adaptHighlightsArray(typesenseHit, result, snippetOrValue) {
        // Algolia lists all searchable attributes in this key, even if there are no matches
        // So do the same and then override highlights

        Object.assign(
            result,
            ...Object.entries(typesenseHit.document).map(([attribute, value]) => ({
                [attribute]: {
                    value: value,
                    matchLevel: "none",
                    matchedWords: [],
                },
            })),
        );

        typesenseHit.highlights.forEach((highlight) => {
            result[highlight.field] = {
                value: highlight[snippetOrValue] || highlight[`${snippetOrValue}s`],
                matchLevel: "full",
                matchedWords: highlight.matched_tokens,
            };

            if (highlight.indices) {
                result[highlight.field]["matchedIndices"] = highlight.indices;
            }
        });

        // Now convert any values that have an array value
        // Also, replace highlight tag
        Object.entries(result).forEach(([k, v]) => {
            const attribute = k;
            const { value, matchLevel, matchedWords, matchedIndices } = v;
            if (value == null) {
                result[attribute] = this._adaptHighlightNullValue();
            } else if (Array.isArray(value)) {
                // Algolia lists all values of the key in highlights, even those that don't have any highlights
                // So add all values of the array field, including highlights
                result[attribute] = [];
                typesenseHit.document[attribute].forEach((unhighlightedValueFromArray, index) => {
                    if (matchedIndices && matchedIndices.includes(index)) {
                        result[attribute].push({
                            value: this._adaptHighlightTag(
                                `${value[matchedIndices.indexOf(index)]}`,
                                this.instantsearchRequest.params.highlightPreTag,
                                this.instantsearchRequest.params.highlightPostTag,
                            ),
                            matchLevel: matchLevel,
                            matchedWords: matchedWords[index],
                        });
                    } else if (typeof unhighlightedValueFromArray === "object") {
                        // Handle arrays of objects
                        // Side note: Typesense does not support highlights for nested objects in this `highlights` array,
                        //  so we pass in an empty object below
                        result[attribute].push(this._adaptHighlightInObjectValue(unhighlightedValueFromArray, {}, snippetOrValue));
                    } else {
                        result[attribute].push({
                            value: `${unhighlightedValueFromArray}`,
                            matchLevel: "none",
                            matchedWords: [],
                        });
                    }
                });
            } else if (typeof value === "object") {
                // Handle nested objects
                // Side note: Typesense does not support highlights for nested objects in this `highlights` array,
                //  so we pass in an empty object below
                result[attribute] = this._adaptHighlightInObjectValue(value, {}, snippetOrValue);
            } else {
                // Convert all values to strings
                result[attribute].value = this._adaptHighlightTag(
                    `${value}`,
                    this.instantsearchRequest.params.highlightPreTag,
                    this.instantsearchRequest.params.highlightPostTag,
                );
            }
        });
    }

    adaptHighlightObject(typesenseHit, result, snippetOrValue) {
        Object.assign(
            result,
            this._adaptHighlightInObjectValue(typesenseHit.document, typesenseHit.highlight, snippetOrValue),
        );
    }

    _adaptHighlightInObjectValue(objectValue, highlightObjectValue, snippetOrValue) {
        return Object.assign(
            {},
            ...Object.entries(objectValue).map(([attribute, value]) => {
                let adaptedValue;
                if (value == null) {
                    adaptedValue = this._adaptHighlightNullValue();
                } else if (Array.isArray(value)) {
                    adaptedValue = this._adaptHighlightInArrayValue(
                        value,
                        highlightObjectValue?.[attribute] ?? [],
                        snippetOrValue,
                    );
                } else if (typeof value === "object") {
                    adaptedValue = this._adaptHighlightInObjectValue(
                        value,
                        highlightObjectValue?.[attribute] ?? {},
                        snippetOrValue,
                    );
                } else {
                    adaptedValue = this._adaptHighlightInPrimitiveValue(value, highlightObjectValue?.[attribute], snippetOrValue);
                }

                return {
                    [attribute]: adaptedValue,
                };
            }),
        );
    }

    _adaptHighlightInArrayValue(arrayValue, highlightArrayValue, snippetOrValue) {
        return arrayValue.map((value, index) => {
            let adaptedValue;
            if (value == null) {
                adaptedValue = this._adaptHighlightNullValue();
            } else if (Array.isArray(value)) {
                adaptedValue = this._adaptHighlightInArrayValue(value, highlightArrayValue?.[index] ?? [], snippetOrValue);
            } else if (typeof value === "object") {
                adaptedValue = this._adaptHighlightInObjectValue(value, highlightArrayValue?.[index] ?? {}, snippetOrValue);
            } else {
                adaptedValue = this._adaptHighlightInPrimitiveValue(value, highlightArrayValue?.[index], snippetOrValue);
            }
            return adaptedValue;
        });
    }

    _adaptHighlightInPrimitiveValue(primitiveValue, highlightPrimitiveValue, snippetOrValue) {
        if (highlightPrimitiveValue != null) {
            const highlightedValue =
                highlightPrimitiveValue[snippetOrValue] ??
                highlightPrimitiveValue["highlight"] ??
                highlightPrimitiveValue["snippet"] ??
                primitiveValue;

            return {
                value: this._adaptHighlightTag(
                    `${highlightedValue}`,
                    this.instantsearchRequest.params.highlightPreTag,
                    this.instantsearchRequest.params.highlightPostTag,
                ),
                matchLevel: (highlightPrimitiveValue.matched_tokens || []).length > 0 ? "full" : "none",
                matchedWords: highlightPrimitiveValue.matched_tokens || [],
            };
        } else {
            return {
                // Convert all values to strings
                value: this._adaptHighlightTag(
                    `${primitiveValue}`,
                    this.instantsearchRequest.params.highlightPreTag,
                    this.instantsearchRequest.params.highlightPostTag,
                ),
                matchLevel: "none",
                matchedWords: [],
            };
        }
    }

    _adaptHighlightNullValue() {
        return {
            value: "",
            matchLevel: "none",
            matchedWords: [],
        };
    }

    _adaptFacets(typesenseFacetCounts) {
        const adaptedResult = {};
        if (Array.isArray(typesenseFacetCounts)) {
            typesenseFacetCounts.forEach((facet) => {
                Object.assign(adaptedResult, {
                    [facet.field_name]: Object.assign({}, ...facet.counts.map((count) => ({ [count.value]: count.count }))),
                });
            });
        }
        return adaptedResult;
    }

    _adaptFacetStats(typesenseFacetCounts) {
        const adaptedResult = {};
        if (Array.isArray(typesenseFacetCounts)) {
            typesenseFacetCounts.forEach((facet) => {
                if (facet.stats && Object.keys(facet.stats).length > 0) {
                    Object.assign(adaptedResult, {
                        [facet.field_name]: facet.stats,
                    });
                }
            });
        }
        return adaptedResult;
    }

    _adaptRenderingContent(typesenseFacetCounts) {
        const adaptedResult = Object.assign({}, this.configuration.renderingContent);

        // Only set facet ordering if the user has not set one
        if (adaptedResult.facetOrdering?.facets?.order == null) {
            adaptedResult.facetOrdering = adaptedResult.facetOrdering || {};
            adaptedResult.facetOrdering.facets = adaptedResult.facetOrdering.facets || {};
            adaptedResult.facetOrdering.facets.order = [
                ...new Set(
                    (Array.isArray(typesenseFacetCounts) ? typesenseFacetCounts : [])
                        .map((fc) => fc["field_name"])
                        .concat(
                            this.allTypesenseResults
                                .map((r) => r.facet_counts || [])
                                .flat()
                                .map((fc) => fc["field_name"])
                                .filter((f) => f),
                        ),
                ),
            ];
        }

        return adaptedResult;
    }

    _adaptUserData(metadata) {
        if (!metadata) return [];

        return Array.isArray(metadata) ? metadata : [metadata];
    }

    adapt() {
        const adaptedRenderingContent = this._adaptRenderingContent(this.typesenseResponse.facet_counts || []);

        // For union search, use union_request_params, otherwise use request_params
        const requestParams = this.typesenseResponse.union_request_params
            ? this.typesenseResponse.union_request_params[0]
            : this.typesenseResponse.request_params;

        const adaptedResult = {
            hits: this.typesenseResponse.grouped_hits
                ? this._adaptGroupedHits(this.typesenseResponse.grouped_hits)
                : this._adaptHits(this.typesenseResponse.hits),
            nbHits: this.typesenseResponse.found,
            page: this.typesenseResponse.union_request_params
                ? this.typesenseResponse.page // Union search already uses 0-based page
                : this.typesenseResponse.page - 1, // Regular search uses 1-based page, convert to 0-based
            nbPages: this._adaptNumberOfPages(),
            hitsPerPage: requestParams?.per_page || 10,
            facets: this._adaptFacets(this.typesenseResponse.facet_counts || []),
            facets_stats: this._adaptFacetStats(this.typesenseResponse.facet_counts || []),
            query: requestParams?.q || "",
            processingTimeMS: this.typesenseResponse.search_time_ms,
            ...(Object.keys(adaptedRenderingContent).length > 0 ? { renderingContent: adaptedRenderingContent } : null),
        };

        // Add appliedRules if metadata is present
        if (this.typesenseResponse.metadata) {
            adaptedResult.appliedRules = ["typesense-override"];
            adaptedResult.userData = this._adaptUserData(this.typesenseResponse.metadata);
        }

        // Add parsed_nl_query if natural language search was used
        if (this.typesenseResponse.parsed_nl_query) {
            adaptedResult.parsed_nl_query = this.typesenseResponse.parsed_nl_query;
        }

        // If no results were found for the search, but there is still a conversation response,
        // still send that as a hit so the conversation is accessible via Instantsearch
        if (this.fullTypesenseResponse.conversation && adaptedResult.hits.length === 0) {
            adaptedResult.hits = [
                {
                    _rawTypesenseConversation: this.fullTypesenseResponse.conversation,
                },
            ];
        }

        // console.log(adaptedResult);
        return adaptedResult;
    }
}

Object.assign(SearchResponseAdapter.prototype, utils);

class FacetSearchResponseAdapter {
    constructor(typesenseResponse, instantsearchRequest) {
        this.typesenseResponse = typesenseResponse;
        this.instantsearchRequest = instantsearchRequest;
    }

    _adaptFacetHits(typesenseFacetCounts) {
        let adaptedResult = [];
        const facet = typesenseFacetCounts.find((facet) => facet.field_name === this.instantsearchRequest.params.facetName);

        if (typeof facet !== "undefined") {
            adaptedResult = facet.counts.map((facetCount) => ({
                value: facetCount.value,
                highlighted: this._adaptHighlightTag(
                    facetCount.highlighted,
                    this.instantsearchRequest.params.highlightPreTag,
                    this.instantsearchRequest.params.highlightPostTag,
                ),
                count: facetCount.count,
            }));
        }

        return adaptedResult;
    }

    adapt() {
        const adaptedResult = {
            facetHits: this._adaptFacetHits(this.typesenseResponse.facet_counts),
            exhaustiveFacetsCount: true,
            processingTimeMS: this.typesenseResponse.search_time_ms,
        };
        return adaptedResult;
    }
}

Object.assign(FacetSearchResponseAdapter.prototype, utils);

class TypesenseInstantsearchAdapter {
    constructor(options) {
        this.updateConfiguration(options);
        this.searchClient = {
            clearCache: () => this.clearCache(),
            search: (instantsearchRequests) => this.searchTypesenseAndAdapt(instantsearchRequests),
            searchForFacetValues: (instantsearchRequests) =>
                this.searchTypesenseForFacetValuesAndAdapt(instantsearchRequests),
        };
    }

    async searchTypesenseAndAdapt(instantsearchRequests) {
        let typesenseResponse;
        try {
            typesenseResponse = await this._adaptAndPerformTypesenseRequest(instantsearchRequests);

            // Check if this is a union search response
            if (typesenseResponse.union_request_params) {
                // Handle union search response - single unified response
                this._validateTypesenseResult(typesenseResponse);
                const responseAdapter = new SearchResponseAdapter(
                    typesenseResponse,
                    instantsearchRequests[0], // Use first request as base
                    this.configuration,
                    [typesenseResponse], // Pass single response as allTypesenseResults
                    typesenseResponse,
                );
                let adaptedResponse = responseAdapter.adapt();

                // InstantSearch expects one result per request, so return the same adapted response for each request
                const adaptedResponses = instantsearchRequests.map(() => adaptedResponse);

                return {
                    results: adaptedResponses,
                };
            } else {
                // Handle regular multi-search response - multiple separate responses
                const adaptedResponses = typesenseResponse.results.map((typesenseResult, index) => {
                    this._validateTypesenseResult(typesenseResult);
                    const responseAdapter = new SearchResponseAdapter(
                        typesenseResult,
                        instantsearchRequests[index],
                        this.configuration,
                        typesenseResponse.results,
                        typesenseResponse,
                    );
                    let adaptedResponse = responseAdapter.adapt();

                    return adaptedResponse;
                });

                return {
                    results: adaptedResponses,
                };
            }
        } catch (error) {
            console.error(error);
            throw error;
        }
    }

    async searchTypesenseForFacetValuesAndAdapt(instantsearchRequests) {
        let typesenseResponse;
        try {
            typesenseResponse = await this._adaptAndPerformTypesenseRequest(instantsearchRequests);

            const adaptedResponses = typesenseResponse.results.map((typesenseResult, index) => {
                this._validateTypesenseResult(typesenseResult);
                const responseAdapter = new FacetSearchResponseAdapter(
                    typesenseResult,
                    instantsearchRequests[index],
                    this.configuration,
                );
                return responseAdapter.adapt();
            });

            return adaptedResponses;
        } catch (error) {
            console.error(error);
            throw error;
        }
    }

    async _adaptAndPerformTypesenseRequest(instantsearchRequests) {
        const requestAdapter = new SearchRequestAdapter(instantsearchRequests, this.typesenseClient, this.configuration);
        const typesenseResponse = await requestAdapter.request();
        return typesenseResponse;
    }

    clearCache() {
        this.typesenseClient = new Typesense.SearchClient(this.configuration.server);
        return this.searchClient;
    }

    updateConfiguration(options) {
        this.configuration = new Configuration(options);
        this.configuration.validate();
        this.typesenseClient = new Typesense.SearchClient(this.configuration.server);
        return true;
    }

    _validateTypesenseResult(typesenseResult) {
        if (typesenseResult.error) {
            throw new Error(`${typesenseResult.code} - ${typesenseResult.error}`);
        }
        if (!typesenseResult.hits && !typesenseResult.grouped_hits) {
            throw new Error(`Did not find any hits. ${typesenseResult.code} - ${typesenseResult.error}`);
        }
    }
}