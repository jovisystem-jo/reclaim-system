package com.reclaim.mobile.screens.activities

import android.net.Uri
import android.os.Bundle
import android.view.View
import android.widget.AdapterView
import android.widget.ArrayAdapter
import android.widget.Spinner
import android.widget.TextView
import android.widget.Toast
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import com.google.android.material.appbar.MaterialToolbar
import com.google.android.material.button.MaterialButton
import com.google.android.material.checkbox.MaterialCheckBox
import com.google.android.material.textfield.TextInputEditText
import com.google.android.material.textfield.TextInputLayout
import com.reclaim.mobile.R
import com.reclaim.mobile.api.network.ApiErrorParser
import com.reclaim.mobile.api.network.MobileRepository
import com.reclaim.mobile.models.Item
import kotlinx.coroutines.launch

class ReportItemActivity : AppCompatActivity() {

    private val repository by lazy { MobileRepository(this) }
    private var selectedImageUri: Uri? = null
    private var currentItem: Item? = null
    private var categories: List<String> = emptyList()
    private var deliveryOptions: List<String> = emptyList()
    private val editingItemId: Int by lazy { intent.getIntExtra(EXTRA_ITEM_ID, 0) }

    private lateinit var toolbar: MaterialToolbar
    private lateinit var titleInput: TextInputEditText
    private lateinit var categorySpinner: Spinner
    private lateinit var brandInput: TextInputEditText
    private lateinit var colorInput: TextInputEditText
    private lateinit var descriptionInput: TextInputEditText
    private lateinit var locationInput: TextInputEditText
    private lateinit var dateInput: TextInputEditText
    private lateinit var timeInput: TextInputEditText
    private lateinit var statusSpinner: Spinner
    private lateinit var deliverySpinner: Spinner
    private lateinit var deliveryGroup: View
    private lateinit var deliveryOtherLayout: TextInputLayout
    private lateinit var deliveryOtherInput: TextInputEditText
    private lateinit var imageText: TextView
    private lateinit var errorText: TextView
    private lateinit var progress: View
    private lateinit var submitButton: MaterialButton
    private lateinit var pickImageButton: MaterialButton
    private lateinit var removeImageCheck: MaterialCheckBox

    private val imagePicker = registerForActivityResult(ActivityResultContracts.GetContent()) { uri ->
        selectedImageUri = uri
        if (uri != null) {
            removeImageCheck.isChecked = false
        }
        renderImageState()
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_report_item)

        toolbar = findViewById(R.id.toolbarReportItem)
        titleInput = findViewById(R.id.editReportTitle)
        categorySpinner = findViewById(R.id.spinnerReportCategory)
        brandInput = findViewById(R.id.editReportBrand)
        colorInput = findViewById(R.id.editReportColor)
        descriptionInput = findViewById(R.id.editReportDescription)
        locationInput = findViewById(R.id.editReportLocation)
        dateInput = findViewById(R.id.editReportDate)
        timeInput = findViewById(R.id.editReportTime)
        statusSpinner = findViewById(R.id.spinnerReportStatus)
        deliverySpinner = findViewById(R.id.spinnerDeliveryLocation)
        deliveryGroup = findViewById(R.id.layoutDeliveryLocationGroup)
        deliveryOtherLayout = findViewById(R.id.layoutDeliveryLocationOther)
        deliveryOtherInput = findViewById(R.id.editDeliveryLocationOther)
        imageText = findViewById(R.id.textReportImage)
        errorText = findViewById(R.id.textReportError)
        progress = findViewById(R.id.progressReport)
        submitButton = findViewById(R.id.buttonSubmitReport)
        pickImageButton = findViewById(R.id.buttonPickImage)
        removeImageCheck = findViewById(R.id.checkRemoveCurrentImage)

        setSupportActionBar(toolbar)
        supportActionBar?.setDisplayHomeAsUpEnabled(true)
        supportActionBar?.title = if (isEditMode()) getString(R.string.edit_report) else getString(R.string.report_item)

        val statusOptions = if (isEditMode()) {
            listOf("lost", "found", "returned", "resolved")
        } else {
            listOf("lost", "found")
        }
        statusSpinner.adapter = ArrayAdapter(this, android.R.layout.simple_spinner_dropdown_item, statusOptions)
        statusSpinner.onItemSelectedListener = object : AdapterView.OnItemSelectedListener {
            override fun onItemSelected(parent: AdapterView<*>?, view: View?, position: Int, id: Long) {
                val selectedStatus = statusOptions[position]
                renderDeliveryState(selectedStatus)
            }

            override fun onNothingSelected(parent: AdapterView<*>?) = Unit
        }

        deliverySpinner.onItemSelectedListener = object : AdapterView.OnItemSelectedListener {
            override fun onItemSelected(parent: AdapterView<*>?, view: View?, position: Int, id: Long) {
                renderDeliveryOtherVisibility()
            }

            override fun onNothingSelected(parent: AdapterView<*>?) = Unit
        }

        if (isEditMode()) {
            submitButton.text = getString(R.string.update_item)
            pickImageButton.text = getString(R.string.replace_image)
        }

        pickImageButton.setOnClickListener {
            imagePicker.launch("image/*")
        }

        removeImageCheck.setOnCheckedChangeListener { _, isChecked ->
            if (isChecked) {
                selectedImageUri = null
            }
            renderImageState()
        }

        submitButton.setOnClickListener {
            submitForm()
        }

        loadOptions()
        if (isEditMode()) {
            loadItemForEditing()
        } else {
            renderImageState()
        }
    }

    private fun isEditMode(): Boolean = editingItemId > 0

    private fun loadOptions() {
        lifecycleScope.launch {
            repository.options()
                .onSuccess { options ->
                    categories = options.categories
                    deliveryOptions = options.deliveryLocations

                    categorySpinner.adapter = ArrayAdapter(
                        this@ReportItemActivity,
                        android.R.layout.simple_spinner_dropdown_item,
                        categories
                    )
                    deliverySpinner.adapter = ArrayAdapter(
                        this@ReportItemActivity,
                        android.R.layout.simple_spinner_dropdown_item,
                        deliveryOptions
                    )

                    currentItem?.let(::bindItem)
                    renderDeliveryState(selectedStatus())
                }
                .onFailure {
                    errorText.visibility = View.VISIBLE
                    errorText.text = ApiErrorParser.message(it)
                }
        }
    }

    private fun loadItemForEditing() {
        progress.visibility = View.VISIBLE
        lifecycleScope.launch {
            repository.itemDetail(editingItemId)
                .onSuccess { item ->
                    if (item.reportedByCurrentUser != true) {
                        Toast.makeText(
                            this@ReportItemActivity,
                            "You can only edit your own reports.",
                            Toast.LENGTH_LONG
                        ).show()
                        finish()
                        return@onSuccess
                    }

                    currentItem = item
                    bindItem(item)
                }
                .onFailure {
                    Toast.makeText(this@ReportItemActivity, ApiErrorParser.message(it), Toast.LENGTH_LONG).show()
                    finish()
                }

            progress.visibility = View.GONE
        }
    }

    private fun bindItem(item: Item) {
        titleInput.setText(item.title)
        brandInput.setText(item.brand)
        colorInput.setText(item.color)
        descriptionInput.setText(item.description)
        locationInput.setText(item.foundLocation.ifBlank { item.location })

        val occurredAt = item.dateFound.orEmpty().trim()
        if (occurredAt.contains(" ")) {
            val pieces = occurredAt.split(" ", limit = 2)
            dateInput.setText(pieces[0])
            timeInput.setText(pieces[1].take(5))
        } else {
            dateInput.setText(occurredAt)
            timeInput.setText("")
        }

        selectSpinnerValue(statusSpinner, item.status)
        if (categories.isNotEmpty()) {
            selectSpinnerValue(categorySpinner, item.category)
        }

        if (deliveryOptions.isNotEmpty()) {
            if (item.deliveryLocation.isNotBlank() && deliveryOptions.contains(item.deliveryLocation)) {
                selectSpinnerValue(deliverySpinner, item.deliveryLocation)
                deliveryOtherInput.setText("")
            } else if (item.deliveryLocation.isNotBlank()) {
                selectSpinnerValue(deliverySpinner, OTHER_DELIVERY_OPTION)
                deliveryOtherInput.setText(item.deliveryLocation)
            } else {
                deliverySpinner.setSelection(0)
                deliveryOtherInput.setText("")
            }
        }

        removeImageCheck.visibility = if (item.imageUrl.isNullOrBlank()) View.GONE else View.VISIBLE
        removeImageCheck.isChecked = false
        renderDeliveryState(item.status)
        renderImageState()
    }

    private fun renderDeliveryState(status: String) {
        val showDelivery = status != "lost"
        deliveryGroup.visibility = if (showDelivery) View.VISIBLE else View.GONE
        if (!showDelivery) {
            deliveryOtherLayout.visibility = View.GONE
            deliveryOtherInput.setText("")
            return
        }

        renderDeliveryOtherVisibility()
    }

    private fun renderDeliveryOtherVisibility() {
        val showOther = deliverySpinner.selectedItem?.toString() == OTHER_DELIVERY_OPTION
        deliveryOtherLayout.visibility = if (showOther) View.VISIBLE else View.GONE
        if (!showOther && currentItem == null) {
            deliveryOtherInput.setText("")
        }
    }

    private fun renderImageState() {
        imageText.text = when {
            selectedImageUri != null -> getString(R.string.selected_image, selectedImageUri.toString())
            removeImageCheck.isChecked -> getString(R.string.current_image_removed)
            currentItem?.imageUrl.isNullOrBlank().not() -> getString(R.string.current_image_kept)
            else -> getString(R.string.no_image_selected)
        }
    }

    private fun submitForm() {
        errorText.visibility = View.GONE
        progress.visibility = View.VISIBLE
        submitButton.isEnabled = false

        lifecycleScope.launch {
            val occurredAt = buildOccurredAt()
            val selectedStatus = selectedStatus()
            val deliverySelection = deliverySpinner.selectedItem?.toString().orEmpty()
            val customDelivery = deliveryOtherInput.text?.toString()?.trim().orEmpty()

            val result = if (isEditMode()) {
                repository.updateItem(
                    itemId = editingItemId,
                    title = titleInput.text?.toString()?.trim().orEmpty(),
                    category = categorySpinner.selectedItem?.toString().orEmpty(),
                    brand = brandInput.text?.toString()?.trim().orEmpty(),
                    color = colorInput.text?.toString()?.trim().orEmpty(),
                    description = descriptionInput.text?.toString()?.trim().orEmpty(),
                    location = locationInput.text?.toString()?.trim().orEmpty(),
                    dateOccurred = occurredAt,
                    status = selectedStatus,
                    deliveryLocation = resolveDeliveryLocation(selectedStatus, deliverySelection, customDelivery),
                    removeImage = removeImageCheck.isChecked,
                    imageUri = selectedImageUri
                )
            } else {
                repository.reportItem(
                    title = titleInput.text?.toString()?.trim().orEmpty(),
                    category = categorySpinner.selectedItem?.toString().orEmpty(),
                    brand = brandInput.text?.toString()?.trim().orEmpty(),
                    color = colorInput.text?.toString()?.trim().orEmpty(),
                    description = descriptionInput.text?.toString()?.trim().orEmpty(),
                    location = locationInput.text?.toString()?.trim().orEmpty(),
                    dateOccurred = dateInput.text?.toString()?.trim().orEmpty(),
                    timeOccurred = timeInput.text?.toString()?.trim().orEmpty(),
                    deliveryOption = if (selectedStatus == "lost") "" else deliverySelection,
                    deliveryLocationOther = if (deliverySelection == OTHER_DELIVERY_OPTION) customDelivery else "",
                    status = selectedStatus,
                    imageUri = selectedImageUri
                )
            }

            result.onSuccess {
                Toast.makeText(
                    this@ReportItemActivity,
                    if (isEditMode()) "Item updated successfully." else "Item reported successfully.",
                    Toast.LENGTH_LONG
                ).show()
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

    private fun buildOccurredAt(): String {
        val date = dateInput.text?.toString()?.trim().orEmpty()
        val time = timeInput.text?.toString()?.trim().orEmpty()
        return if (time.isBlank()) date else "$date $time"
    }

    private fun selectedStatus(): String = statusSpinner.selectedItem?.toString().orEmpty()

    private fun resolveDeliveryLocation(status: String, selectedDelivery: String, customDelivery: String): String {
        if (status == "lost") {
            return ""
        }

        return if (selectedDelivery == OTHER_DELIVERY_OPTION) customDelivery else selectedDelivery
    }

    private fun selectSpinnerValue(spinner: Spinner, value: String) {
        val adapter = spinner.adapter ?: return
        for (index in 0 until adapter.count) {
            if (adapter.getItem(index)?.toString() == value) {
                spinner.setSelection(index)
                return
            }
        }
    }

    override fun onSupportNavigateUp(): Boolean {
        finish()
        return true
    }

    companion object {
        const val EXTRA_ITEM_ID = "item_id"
        private const val OTHER_DELIVERY_OPTION = "Other (Please specify)"
    }
}
