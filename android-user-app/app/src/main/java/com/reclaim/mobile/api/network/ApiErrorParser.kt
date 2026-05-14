package com.reclaim.mobile.api.network

import com.google.gson.Gson
import com.google.gson.reflect.TypeToken
import com.reclaim.mobile.models.ApiEnvelope
import retrofit2.HttpException

object ApiErrorParser {
    private val gson = Gson()

    fun message(throwable: Throwable): String {
        if (throwable is HttpException) {
            val errorBody = throwable.response()?.errorBody()?.string()
            if (!errorBody.isNullOrBlank()) {
                return try {
                    val type = object : TypeToken<ApiEnvelope<Any>>() {}.type
                    val envelope: ApiEnvelope<Any> = gson.fromJson(errorBody, type)
                    envelope.message.ifBlank { throwable.message() ?: "Request failed." }
                } catch (_: Throwable) {
                    throwable.message() ?: "Request failed."
                }
            }
        }

        return throwable.message ?: "Something went wrong."
    }
}
