package com.reclaim.mobile.screens.activities

import android.net.Uri
import android.os.Bundle
import android.view.View
import android.widget.ArrayAdapter
import android.widget.Spinner
import android.widget.TextView
import android.widget.Toast
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import com.google.android.material.button.MaterialButton
import com.google.android.material.textfield.TextInputEditText
import com.reclaim.mobile.R
import com.reclaim.mobile.api.network.ApiErrorParser
import com.reclaim.mobile.api.network.MobileRepository
import kotlinx.coroutines.launch

class ReportItemActivity : AppCompatActivity() {

    private val repository by lazy { MobileRepository(this) }
    private var selectedImageUri: Uri? = null

    private val imagePicker = registerForActivityResult(ActivityResultContracts.GetContent()) { uri ->
        selectedImageUri = uri
        findViewById<TextView>(R.id.textReportImage).text = uri?.toString() ?: "No image selected"
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_report_item)

        supportActionBar?.setDisplayHomeAsUpEnabled(true)
        supportActionBar?.title = getString(R.string.report_item)

        val title: TextInputEditText = findViewById(R.id.editReportTitle)
        val category: Spinner = findViewById(R.id.spinnerReportCategory)
        val brand: TextInputEditText = findViewById(R.id.editReportBrand)
        val color: TextInputEditText = findViewById(R.id.editReportColor)
        val description: TextInputEditText = findViewById(R.id.editReportDescription)
        val location: TextInputEditText = findViewById(R.id.editReportLocation)
        val date: TextInputEditText = findViewById(R.id.editReportDate)
        val time: TextInputEditText = findViewById(R.id.editReportTime)
        val status: Spinner = findViewById(R.id.spinnerReportStatus)
        val delivery: Spinner = findViewById(R.id.spinnerDeliveryLocation)
        val errorText: TextView = findViewById(R.id.textReportError)
        val progress: View = findViewById(R.id.progressReport)
        val submitButton: MaterialButton = findViewById(R.id.buttonSubmitReport)
        val pickImageButton: MaterialButton = findViewById(R.id.buttonPickImage)

        status.adapter = ArrayAdapter(this, android.R.layout.simple_spinner_dropdown_item, listOf("lost", "found"))

        lifecycleScope.launch {
            repository.options()
                .onSuccess { options ->
                    category.adapter = ArrayAdapter(this@ReportItemActivity, android.R.layout.simple_spinner_dropdown_item, options.categories)
                    delivery.adapter = ArrayAdapter(this@ReportItemActivity, android.R.layout.simple_spinner_dropdown_item, options.deliveryLocations)
                }
                .onFailure {
                    errorText.visibility = View.VISIBLE
                    errorText.text = ApiErrorParser.message(it)
                }
        }

        pickImageButton.setOnClickListener {
            imagePicker.launch("image/*")
        }

        submitButton.setOnClickListener {
            errorText.visibility = View.GONE
            progress.visibility = View.VISIBLE
            submitButton.isEnabled = false

            lifecycleScope.launch {
                repository.reportItem(
                    title = title.text?.toString()?.trim().orEmpty(),
                    category = category.selectedItem?.toString().orEmpty(),
                    brand = brand.text?.toString()?.trim().orEmpty(),
                    color = color.text?.toString()?.trim().orEmpty(),
                    description = description.text?.toString()?.trim().orEmpty(),
                    location = location.text?.toString()?.trim().orEmpty(),
                    dateOccurred = date.text?.toString()?.trim().orEmpty(),
                    timeOccurred = time.text?.toString()?.trim().orEmpty(),
                    deliveryOption = delivery.selectedItem?.toString().orEmpty(),
                    status = status.selectedItem?.toString().orEmpty(),
                    imageUri = selectedImageUri
                ).onSuccess {
                    Toast.makeText(this@ReportItemActivity, "Item reported successfully.", Toast.LENGTH_LONG).show()
                    setResult(RESULT_OK)
                    finish()
                }.onFailure {
                    errorText.visibility = View.VISIBLE
                    errorText.text = ApiErrorParser.message(it)
                }

                progress.visibility = View.GONE
                submitButton.isEnabled = true
            }
        }
    }

    override fun onSupportNavigateUp(): Boolean {
        finish()
        return true
    }
}
